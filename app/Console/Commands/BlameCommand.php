<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\progress;

/**
 * Instrument B — attribute each test method to its introducing commit's author-date and
 * bucket pre/post-AI. This isolates tests *authored in* each era (flow), unlike a snapshot
 * which is contaminated by old sticky tests (state).
 *
 * Scope: blame runs once per repository against the NEWEST extracted snapshot (head if
 * extracted, else the highest extracted major) — introduction dates are properties of
 * methods, not of versions. The cutoff is commit-author-date based, never framework-version
 * based; default from config('analyser.ai_cutoff'), `--cutoff=` overrides for sensitivity
 * runs. Attribution prefers `git log -L <start>,<end>:<path> --reverse` (first commit =
 * introduction), falling back to `git blame -M -C` on the signature line. Deliberately
 * serial: blame is the slow stage, and serial keeps every failure attributable.
 *
 * Aa (Appendix B, H3b): each introducing commit's author, committer and co-authorship
 * trailers are additionally inspected in memory, matched case-insensitively against
 * config('analyser.agent_patterns'), and reduced to a Boolean indicator with the matched
 * tool name. No author name or email ever reaches the database, a CSV, a log line, or
 * console output.
 */
class BlameCommand extends Command
{
    protected $signature = 'analyse:blame {full_name : owner/repo} {--cutoff= : override the configured ai_cutoff (sensitivity runs)}';

    protected $description = 'Attribute each test method to its author-date and tag the AI window';

    /** @var array<string, int> memoised commit-count per file path (at the blamed sha) */
    private array $fileCommitCounts = [];

    /** @var array<string, array{0: string, 1: string}|null> memoised sole-commit per file path */
    private array $soleFileCommits = [];

    /**
     * Memoised agent-trace verdict per introducing sha — many methods share one commit,
     * and the process spawn is the cost.
     *
     * @var array<string, array{0: bool, 1: string|null}>
     */
    private array $agentTraces = [];

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

        $snapshot = $this->newestExtractedSnapshot($repository);
        if ($snapshot === null) {
            $this->error('No extracted snapshot — run analyse:extract first.');

            return self::FAILURE;
        }

        $cutoff = Carbon::parse((string) ($this->option('cutoff') ?: config('analyser.ai_cutoff')));

        // Idempotency: a re-run replaces the whole Instrument B layer for this repository.
        TestObservation::where('repository_id', $repository->id)->update([
            'introduced_commit_sha' => null,
            'introduced_author_date' => null,
            'ai_window' => null,
            'agent_authored' => null,
            'agent_tool' => null,
        ]);

        $observations = $snapshot->observations()->get();
        $attributed = 0;
        $failed = 0;
        $traced = 0;
        $windows = ['pre' => 0, 'post' => 0];

        progress(
            label: "Blaming {$observations->count()} test methods @ ".substr((string) $snapshot->commit_sha, 0, 12),
            steps: $observations,
            callback: function (TestObservation $observation, $progress) use ($root, $snapshot, $cutoff, &$attributed, &$failed, &$traced, &$windows): void {
                $progress->hint($observation->file_path);

                $introduction = $this->introducingCommit($root, (string) $snapshot->commit_sha, $observation);
                if ($introduction === null) {
                    $failed++; // Instrument B columns stay null — same philosophy as parse failures.

                    return;
                }

                [$sha, $authorDate] = $introduction;
                $window = Carbon::parse($authorDate)->lessThan($cutoff) ? 'pre' : 'post';
                [$agentAuthored, $agentTool] = $this->agentTraces[$sha] ??= $this->matchAgentIdentity($root, $sha);

                $observation->update([
                    'introduced_commit_sha' => $sha,
                    'introduced_author_date' => Carbon::parse($authorDate),
                    'ai_window' => $window,
                    'agent_authored' => $agentAuthored,
                    'agent_tool' => $agentTool,
                ]);

                $attributed++;
                $windows[$window]++;
                if ($agentAuthored) {
                    $traced++;
                }
            },
        );

        $this->info(sprintf(
            'Attributed %d of %d test methods (cutoff %s): %d pre-AI, %d post-AI, %d agent-traced. %d unattributable (columns left null).',
            $attributed,
            $observations->count(),
            $cutoff->toDateString(),
            $windows['pre'],
            $windows['post'],
            $traced,
            $failed,
        ));

        return self::SUCCESS;
    }

    /**
     * Head if extracted, else the highest extracted major.
     */
    private function newestExtractedSnapshot(Repository $repository): ?Snapshot
    {
        return $repository->snapshots()
            ->has('observations')
            ->orderByRaw("kind = 'head' desc")
            ->orderByDesc('framework_version')
            ->first();
    }

    /**
     * The commit that introduced this method's definition line range.
     *
     * @return array{0: string, 1: string}|null [sha, ISO-8601 author date]
     */
    private function introducingCommit(string $root, string $sha, TestObservation $observation): ?array
    {
        $path = $observation->file_path;
        $start = (int) $observation->start_line;
        $end = (int) $observation->end_line;

        if ($start < 1 || $end < $start) {
            return null;
        }

        // Memoisation: when only one commit ever touched the file, every method in it shares
        // that introducing commit — one invocation covers the whole file.
        if ($this->commitCountFor($root, $sha, $path) === 1) {
            return $this->soleFileCommits[$path] ??= $this->soleCommitFor($root, $sha, $path);
        }

        $traced = Process::path($root)->run([
            'git', 'log', '--reverse', '-s', '--format=%H %aI', "-L{$start},{$end}:{$path}", $sha,
        ]);
        if ($traced->successful()) {
            $first = $this->firstShaDateLine($traced->output());
            if ($first !== null) {
                return $first;
            }
        }

        return $this->blameSignatureLine($root, $sha, $path, $start);
    }

    private function commitCountFor(string $root, string $sha, string $path): int
    {
        return $this->fileCommitCounts[$path] ??= substr_count(trim(Process::path($root)
            ->run(['git', 'log', '--follow', '--format=%H', $sha, '--', $path])
            ->output()), "\n") + 1;
    }

    /** @return array{0: string, 1: string}|null */
    private function soleCommitFor(string $root, string $sha, string $path): ?array
    {
        $output = Process::path($root)
            ->run(['git', 'log', '--follow', '--format=%H %aI', $sha, '--', $path])
            ->output();

        return $this->firstShaDateLine($output);
    }

    /**
     * Fallback for paths `git log -L` cannot trace: blame the signature line, honouring
     * moves/copies (-M -C).
     *
     * @return array{0: string, 1: string}|null
     */
    private function blameSignatureLine(string $root, string $sha, string $path, int $line): ?array
    {
        $blamed = Process::path($root)->run([
            'git', 'blame', '-M', '-C', '-L', "{$line},{$line}", '--porcelain', $sha, '--', $path,
        ]);
        if (! $blamed->successful()) {
            return null;
        }

        $lines = explode("\n", $blamed->output());
        $commitSha = explode(' ', $lines[0])[0];
        if (preg_match('/^[0-9a-f]{40}$/', $commitSha) !== 1) {
            return null;
        }

        $epoch = null;
        foreach ($lines as $header) {
            if (str_starts_with($header, 'author-time ')) {
                $epoch = (int) substr($header, strlen('author-time '));

                break;
            }
        }
        if ($epoch === null) {
            return null;
        }

        return [$commitSha, Carbon::createFromTimestampUTC($epoch)->toIso8601String()];
    }

    /**
     * Aa: does this commit carry a recognised coding-agent identity? Author, committer and
     * the full message body (which holds Co-authored-by/Co-Authored-By trailers in whatever
     * casing) are lowercased and searched as one buffer — substring search over the whole
     * message is what catches the trailer-casing variance Hora & Robbes report; trailers
     * are deliberately not parsed structurally.
     *
     * Ethics constraint: the buffer is inspected in memory and reduced to a Boolean with
     * the matched tool name, then discarded. If an author string ever needs to outlive
     * this method, restructure instead.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function matchAgentIdentity(string $root, string $sha): array
    {
        $shown = Process::path($root)->run([
            'git', 'show', '-s', '--format=%an%x00%ae%x00%cn%x00%ce%x00%B', $sha,
        ]);
        if (! $shown->successful()) {
            return [false, null];
        }

        $haystack = strtolower($shown->output());
        foreach ((array) config('analyser.agent_patterns', []) as $pattern) {
            if (str_contains($haystack, strtolower((string) $pattern))) {
                return [true, (string) $pattern];
            }
        }

        return [false, null];
    }

    /** @return array{0: string, 1: string}|null */
    private function firstShaDateLine(string $output): ?array
    {
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^([0-9a-f]{40}) (\S+)$/', trim($line), $matches) === 1) {
                return [$matches[1], $matches[2]];
            }
        }

        return null;
    }
}
