<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Appendix C's classifier-validation protocol, step 1: draw a reproducible seeded random
 * sample of test observations for hand-labelling. The export carries the source excerpt
 * and a blank human_label column — and deliberately NOT test_type or test_type_rule: the
 * protocol requires labelling blind to classifier output, and a CSV that leaks the label
 * would invalidate the kappa. Score the returned labels with analyse:score-types.
 */
class SampleTypesCommand extends Command
{
    protected $signature = 'analyse:sample-types
        {--n=100 : sample size}
        {--seed= : PRNG seed (record it — the same seed reproduces the same sample)}
        {--export= : path of the .csv to write}';

    protected $description = 'Draw a reproducible random sample of test observations for blind hand-labelling';

    public function handle(): int
    {
        $seed = $this->option('seed');
        $export = $this->option('export');
        if (! is_string($seed) || $seed === '' || ! is_string($export) || $export === '') {
            $this->error('Both --seed= and --export= are required: the seed is what makes the sample reproducible.');

            return self::FAILURE;
        }

        $ids = TestObservation::query()->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            $this->error('No test observations to sample — run analyse:extract first.');

            return self::FAILURE;
        }

        $n = min((int) $this->option('n'), count($ids));

        // Seeded Fisher-Yates over the id list, then the first n, re-sorted for reading.
        mt_srand((int) $seed);
        for ($i = count($ids) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
        $sample = array_slice($ids, 0, $n);
        sort($sample);

        File::ensureDirectoryExists(dirname($export));
        $handle = fopen($export, 'w');
        if ($handle === false) {
            $this->error("Could not open {$export} for writing.");

            return self::FAILURE;
        }

        fputcsv($handle, ['id', 'repository', 'file_path', 'identifier', 'source_excerpt', 'human_label']);
        foreach (TestObservation::query()->with(['repository', 'snapshot'])->findMany($sample)->sortBy('id') as $observation) {
            fputcsv($handle, [
                $observation->id,
                (string) $observation->repository?->full_name,
                $observation->file_path,
                $observation->identifier,
                $this->sourceExcerpt($observation),
                '',
            ]);
        }
        fclose($handle);

        $this->info(sprintf(
            'Sampled %d of %d observations (seed %s) to %s — label the human_label column blind, then run analyse:score-types.',
            $n,
            count($ids),
            $seed,
            $export,
        ));

        return self::SUCCESS;
    }

    /**
     * The method's source at the snapshot it was observed in: `git show <sha>:<path>`
     * against the clone, sliced to the recorded definition range; the working tree is the
     * fallback for snapshots git cannot serve (e.g. synthetic fixtures).
     */
    private function sourceExcerpt(TestObservation $observation): string
    {
        $root = (string) $observation->repository?->clone_path;
        if ($root === '' || ! is_dir($root)) {
            return '(clone unavailable)';
        }

        $source = null;
        $shown = Process::path($root)->run([
            'git', 'show', "{$observation->snapshot?->commit_sha}:{$observation->file_path}",
        ]);
        if ($shown->successful()) {
            $source = $shown->output();
        } elseif (is_file("{$root}/{$observation->file_path}")) {
            $source = (string) file_get_contents("{$root}/{$observation->file_path}");
        }

        if ($source === null) {
            return '(source unavailable)';
        }
        if ($observation->start_line === null || $observation->end_line === null) {
            return '(no line range recorded)';
        }

        $lines = explode("\n", $source);

        return implode("\n", array_slice($lines, $observation->start_line - 1, $observation->end_line - $observation->start_line + 1));
    }
}
