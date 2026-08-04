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
        $defaultBranch = $this->defaultBranch($dest);
        $metadata = $this->fetchGitHubMetadata($fullName);

        Repository::updateOrCreate(
            ['full_name' => $fullName],
            [
                'owner' => $owner,
                'name' => $name,
                'url' => $url,
                'clone_path' => $dest,
                'head_sha' => $headSha,
                'default_branch' => $defaultBranch,
                'cloned_at' => now(),
                ...$metadata,
            ],
        );

        $this->info("Acquired {$fullName} @ {$headSha} (default branch: ".($defaultBranch ?? 'unknown').')');

        return self::SUCCESS;
    }

    /**
     * The branch HEAD points at — the trunk that analyse:snapshot's --first-parent walk is
     * relative to, without which a reader cannot verify the walk. Falls back to the remote's
     * HEAD symref where the local HEAD is detached; null where neither resolves.
     */
    private function defaultBranch(string $dest): ?string
    {
        $branch = trim(Process::path($dest)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])->output());
        if ($branch !== '' && $branch !== 'HEAD') {
            return $branch;
        }

        $symref = trim(Process::path($dest)->run(['git', 'symbolic-ref', 'refs/remotes/origin/HEAD'])->output());

        return $symref !== '' ? str_replace('refs/remotes/origin/', '', $symref) : null;
    }

    /**
     * Best-effort provenance from the GitHub API; the clone itself is the primary artefact,
     * so an unreachable API degrades to nulls rather than failing the acquisition.
     *
     * Stars, watchers, forks and archived status are the corpus's descriptive
     * characteristics (Appendix A) — never criteria; popularity is deliberately not a
     * quality filter. They are recorded here because they are unrecoverable if a repository
     * is deleted or archived later. The fork flag and parent feed screening's
     * independent-history criterion.
     *
     * @return array<string, mixed>
     */
    private function fetchGitHubMetadata(string $fullName): array
    {
        $empty = [
            'license' => null, 'github_created_at' => null, 'stars' => null,
            'watchers' => null, 'forks' => null, 'archived' => null,
            'is_fork' => null, 'fork_parent' => null,
        ];

        try {
            $response = Http::acceptJson()->get("https://api.github.com/repos/{$fullName}");
        } catch (ConnectionException) {
            $this->warn('GitHub API unreachable — descriptive characteristics left empty.');

            return $empty;
        }

        if (! $response->successful()) {
            $this->warn("GitHub API returned {$response->status()} — descriptive characteristics left empty.");

            return $empty;
        }

        return [
            'license' => $response->json('license.spdx_id'),
            'github_created_at' => $response->json('created_at'),
            'stars' => $response->json('stargazers_count'),
            'watchers' => $response->json('subscribers_count'),
            'forks' => $response->json('forks_count'),
            'archived' => $response->json('archived'),
            'is_fork' => $response->json('fork'),
            'fork_parent' => $response->json('parent.full_name'),
        ];
    }
}
