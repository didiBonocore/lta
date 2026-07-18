<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Repository;
use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

/**
 * Instrument B — attribute each test method to its introducing commit's author-date and
 * bucket pre/post-AI. This isolates tests *authored in* each era (flow), unlike a snapshot
 * which is contaminated by old sticky tests (state). The cutoff is commit-author-date based
 * and comes from config('analyser.ai_cutoff') — never framework-version based, because
 * Laravel 13 is AI-native and a version cutoff would conflate the two instruments.
 */
class BlameCommand extends Command
{
    protected $signature = 'analyse:blame {full_name : owner/repo} {--cutoff= : override the configured ai_cutoff (sensitivity runs)}';

    protected $description = 'Attribute each test method to its author-date and tag the AI window';

    public function handle(): int
    {
        $repository = Repository::where('full_name', $this->argument('full_name'))->first();
        if ($repository === null) {
            $this->error('Repository not acquired yet — run analyse:acquire first.');

            return self::FAILURE;
        }

        $root = (string) $repository->clone_path;
        if (! is_dir($root)) {
            $this->error("Clone path missing on disk: {$root}");

            return self::FAILURE;
        }

        $cutoff = Carbon::parse((string) ($this->option('cutoff') ?: config('analyser.ai_cutoff')));

        // One attribution per distinct test method; the same method observed in several
        // snapshots shares one introducing commit.
        $groups = TestObservation::where('repository_id', $repository->id)
            ->get(['id', 'file_path', 'identifier', 'front_end'])
            ->groupBy(fn (TestObservation $o): string => "{$o->front_end}|{$o->file_path}|{$o->identifier}");

        $attributed = 0;
        $unattributed = 0;
        $windows = ['pre' => 0, 'post' => 0];

        foreach ($groups as $observations) {
            /** @var Collection<int, TestObservation> $observations */
            $first = $observations->first();
            $introduction = $this->introducingCommit($root, $first);

            if ($introduction === null) {
                $unattributed++;

                continue;
            }

            [$sha, $authorDate] = $introduction;
            $window = Carbon::parse($authorDate)->lessThan($cutoff) ? 'pre' : 'post';

            TestObservation::whereIn('id', $observations->pluck('id'))->update([
                'introduced_commit_sha' => $sha,
                'introduced_author_date' => Carbon::parse($authorDate),
                'ai_window' => $window,
            ]);

            $attributed++;
            $windows[$window]++;
        }

        $this->info(sprintf(
            'Attributed %d of %d test methods (cutoff %s): %d pre-AI, %d post-AI. %d unattributable.',
            $attributed,
            $groups->count(),
            $cutoff->toDateString(),
            $windows['pre'],
            $windows['post'],
            $unattributed,
        ));

        return self::SUCCESS;
    }

    /**
     * The oldest commit whose diff introduced this test: pickaxe on the definition marker
     * scoped to the file's history, falling back to the commit that added the file.
     *
     * @return array{0: string, 1: string}|null [sha, ISO-8601 author date]
     */
    private function introducingCommit(string $root, TestObservation $observation): ?array
    {
        $needle = $observation->front_end === 'phpunit'
            ? "function {$observation->identifier}"
            : $observation->identifier;

        $picked = $this->firstLogLine($root, [
            'git', 'log', '--reverse', '--format=%H|%aI', '-S', $needle, '--', $observation->file_path,
        ]);
        if ($picked !== null) {
            return $picked;
        }

        // Renames defeat the path-scoped pickaxe; the file's add-commit is the honest bound.
        $added = Process::path($root)->run([
            'git', 'log', '--follow', '--diff-filter=A', '--format=%H|%aI', '--', $observation->file_path,
        ]);
        if (! $added->successful()) {
            return null;
        }

        $lines = array_values(array_filter(explode("\n", trim($added->output()))));
        $oldest = end($lines);

        return $oldest === false ? null : $this->splitLogLine($oldest);
    }

    /**
     * @param  list<string>  $command
     * @return array{0: string, 1: string}|null
     */
    private function firstLogLine(string $root, array $command): ?array
    {
        $result = Process::path($root)->run($command);
        if (! $result->successful()) {
            return null;
        }

        $first = strtok(trim($result->output()), "\n");

        return $first === false || $first === '' ? null : $this->splitLogLine($first);
    }

    /** @return array{0: string, 1: string}|null */
    private function splitLogLine(string $line): ?array
    {
        $parts = explode('|', trim($line), 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : null;
    }
}
