<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Repository;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\spin;

/**
 * Stage 0 — full git clone (never --depth 1) and record provenance: HEAD sha, clone date,
 * plus license and creation date from the GitHub API (sampling-frame evidence for the paper).
 */
class AcquireCommand extends Command
{
    protected $signature = 'analyse:acquire {full_name : owner/repo} {--path=storage/corpus}';

    protected $description = 'Full-clone a repository into the local corpus and record HEAD + clone date';

    public function handle(): int
    {
        $fullName = (string) $this->argument('full_name');
        if (substr_count($fullName, '/') !== 1) {
            $this->error("Expected owner/repo, got: {$fullName}");

            return self::FAILURE;
        }

        [$owner, $name] = explode('/', $fullName, 2);
        $dest = base_path($this->option('path')."/{$owner}__{$name}");
        $url = "https://github.com/{$fullName}.git";

        if (! is_dir($dest)) {
            spin(
                fn () => Process::forever()->run(['git', 'clone', $url, $dest])->throw(),
                "Cloning {$fullName} (full history)…",
            );
        } else {
            $this->info("Already cloned: {$dest}");
        }

        $headSha = trim(Process::path($dest)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
        $metadata = $this->fetchGitHubMetadata($fullName);

        Repository::updateOrCreate(
            ['full_name' => $fullName],
            [
                'owner' => $owner,
                'name' => $name,
                'url' => $url,
                'clone_path' => $dest,
                'head_sha' => $headSha,
                'cloned_at' => now(),
                ...$metadata,
            ],
        );

        $this->info("Acquired {$fullName} @ {$headSha}");

        return self::SUCCESS;
    }

    /**
     * Best-effort provenance from the GitHub API; the clone itself is the primary artefact,
     * so an unreachable API degrades to nulls rather than failing the acquisition.
     *
     * @return array{license: ?string, github_created_at: ?string}
     */
    private function fetchGitHubMetadata(string $fullName): array
    {
        try {
            $response = Http::acceptJson()->get("https://api.github.com/repos/{$fullName}");
        } catch (ConnectionException) {
            $this->warn('GitHub API unreachable — license/created_at left empty.');

            return ['license' => null, 'github_created_at' => null];
        }

        if (! $response->successful()) {
            $this->warn("GitHub API returned {$response->status()} — license/created_at left empty.");

            return ['license' => null, 'github_created_at' => null];
        }

        return [
            'license' => $response->json('license.spdx_id'),
            'github_created_at' => $response->json('created_at'),
        ];
    }
}
