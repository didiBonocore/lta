<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Repository;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\spin;

/**
 * Stage 0 — full git clone (never --depth 1) and record provenance.
 */
class AcquireCommand extends Command
{
    protected $signature = 'analyse:acquire {full_name : owner/repo} {--path=storage/corpus}';

    protected $description = 'Full-clone a repository into the local corpus and record HEAD + clone date';

    public function handle(): int
    {
        $fullName = $this->argument('full_name');
        [$owner, $name] = explode('/', $fullName, 2);
        $dest = base_path($this->option('path')."/{$owner}__{$name}");
        $url = "https://github.com/{$fullName}.git";

        if (! is_dir($dest)) {
            spin(
                fn () => (new Process(['git', 'clone', $url, $dest]))->setTimeout(null)->mustRun(),
                "Cloning {$fullName} (full history)…",
            );
        } else {
            $this->info("Already cloned: {$dest}");
        }

        $headSha = trim((new Process(['git', '-C', $dest, 'rev-parse', 'HEAD']))->mustRun()->getOutput());

        Repository::updateOrCreate(
            ['full_name' => $fullName],
            [
                'owner' => $owner,
                'name' => $name,
                'url' => $url,
                'clone_path' => $dest,
                'head_sha' => $headSha,
                'cloned_at' => now(),
                // TODO(M1): fetch license + github_created_at from api.github.com/repos/{full_name}
            ],
        );

        $this->info("Acquired {$fullName} @ {$headSha}");

        return self::SUCCESS;
    }
}
