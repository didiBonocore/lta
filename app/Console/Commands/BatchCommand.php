<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * Corpus driver: reads owner/repo per line (blank lines and #-comments ignored) and runs
 * the whole pipeline per repository, serially — acquire → snapshot → extract → blame →
 * verify. A failing stage skips the repository's remaining stages but never aborts the
 * batch (continue-on-failure); failed repositories are listed at the end and the exit code
 * is non-zero so the batch can gate scripts, mirroring analyse:verify.
 *
 * Mining has a mandatory human gate in the middle — the tutorial/demo criterion cannot be
 * automated — so there are two entry points, not one end-to-end command:
 *
 *   analyse:batch --screen candidates.txt   acquire + screen every candidate, then stop
 *       (operator answers manual criteria, runs analyse:screen --finalise)
 *   analyse:batch corpus.txt                snapshot + extract + blame + verify
 *
 * A single command spanning the gate would either skip it or block on it.
 */
class BatchCommand extends Command
{
    private const array STAGES = [
        'analyse:acquire',
        'analyse:snapshot',
        'analyse:extract',
        'analyse:blame',
        'analyse:verify',
    ];

    private const array SCREEN_STAGES = [
        'analyse:acquire',
        'analyse:screen',
    ];

    protected $signature = 'analyse:batch
        {file : text file with one owner/repo per line}
        {--screen : acquire and screen candidates only, stopping at the manual gate}';

    protected $description = 'Run the full pipeline over a corpus file, serially, continue-on-failure';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Corpus file not found: {$path}");

            return self::FAILURE;
        }

        $repositories = collect(explode("\n", (string) file_get_contents($path)))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'))
            ->values();

        if ($repositories->isEmpty()) {
            $this->error('Corpus file contains no repositories.');

            return self::FAILURE;
        }

        $stages = $this->option('screen') ? self::SCREEN_STAGES : self::STAGES;
        $summary = [];
        $failed = [];

        foreach ($repositories as $fullName) {
            $this->components->info("▶ {$fullName}");

            $row = [$fullName];
            $broken = false;

            foreach ($stages as $stage) {
                if ($broken) {
                    $row[] = '–';

                    continue;
                }

                $row[] = $this->runStage($stage, $fullName) ? '✓' : '✗';
                if ($row[count($row) - 1] === '✗') {
                    $broken = true;
                }
            }

            if ($broken) {
                $failed[] = $fullName;
            }
            $summary[] = $row;
        }

        $this->components->info('Batch summary');
        $this->table(['repository', ...array_map(fn (string $s): string => str_replace('analyse:', '', $s), $stages)], $summary);

        if ($this->option('screen') && $failed === []) {
            $this->info('Screening done. Answer the manual criteria (analyse:screen owner/repo --manual=... --reason="..."), then run analyse:screen --finalise.');
        }

        if ($failed !== []) {
            $this->error(sprintf('%d of %d repositories failed: %s', count($failed), $repositories->count(), implode(', ', $failed)));

            return self::FAILURE;
        }

        $this->info("All {$repositories->count()} repositories completed.");

        return self::SUCCESS;
    }

    private function runStage(string $stage, string $fullName): bool
    {
        try {
            return $this->call($stage, ['full_name' => $fullName]) === self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn("{$stage} threw for {$fullName}: {$e->getMessage()}");

            return false;
        }
    }
}
