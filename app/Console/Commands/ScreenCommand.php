<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Discovery\SuiteDiscovery;
use App\Analysis\FrontEnd\FrontEndRouter;
use App\Analysis\Tree\WorkingTree;
use App\Analysis\Versioning\TrunkMajorWalk;
use App\Models\Candidate;
use App\Models\Repository;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PhpParser\Error as ParseError;
use Symfony\Component\Finder\Finder;

/**
 * Candidate screening — the executable form of Appendix A's inclusion criteria, producing
 * the decision log as a byproduct of running them rather than a document typed up from
 * memory.
 *
 * Screening is two-phase because two criteria are pool-relative: candidates below the FIRST
 * QUARTILE of the candidate pool on test file proportion and test lines-of-code proportion
 * are excluded (Miranda et al.), rather than a fixed threshold being imposed. Phase 1
 * (analyse:screen owner/repo) measures and records raw values per candidate and decides
 * every criterion decidable alone. Phase 2 (--finalise) computes the quartiles across the
 * pool and writes every candidate's verdict — re-running it after adding candidates REVISES
 * earlier verdicts, because the quartile moves; that is correct behaviour, not a bug.
 *
 * The tutorial/coursework/demonstration criterion is human judgement and is never faked:
 * --manual=include|exclude --reason="..." records the decision, and a candidate with an
 * unanswered manual criterion finalises as pending, never as included.
 */
class ScreenCommand extends Command
{
    /**
     * Below this pool size a first quartile is dominated by one or two observations and the
     * pool-relative criteria stop meaning anything; --finalise refuses rather than emitting
     * a degenerate threshold. Eight gives two observations per quartile bucket — the
     * smallest pool where "below the first quartile" describes a distribution rather than
     * an arbitrary member.
     */
    private const MINIMUM_FINALISE_POOL = 8;

    protected $signature = 'analyse:screen
        {full_name? : owner/repo to screen (phase 1) or annotate with --manual}
        {--manual= : record the human judgement for the tutorial/demo criterion: include|exclude}
        {--reason= : free-text justification for --manual (required with it)}
        {--finalise : phase 2 — compute pool quartiles and write final verdicts}
        {--export= : write the decision log as CSV to this path}
        {--corpus=corpus.txt : where --finalise writes the included set, one owner/repo per line}';

    protected $description = 'Screen corpus candidates against Appendix A and maintain the published decision log';

    public function handle(SuiteDiscovery $discovery, TrunkMajorWalk $walk): int
    {
        $fullName = $this->argument('full_name');

        if ($fullName !== null && $this->option('manual') !== null) {
            return $this->recordManual((string) $fullName);
        }

        if ($fullName !== null) {
            return $this->screenCandidate((string) $fullName, $discovery, $walk);
        }

        if ($this->option('finalise')) {
            $result = $this->finalise();
            if ($result === self::SUCCESS && $this->option('export') !== null) {
                return $this->exportCsv((string) $this->option('export'));
            }

            return $result;
        }

        if ($this->option('export') !== null) {
            return $this->exportCsv((string) $this->option('export'));
        }

        $this->error('Nothing to do: pass owner/repo to screen, --manual to judge, --finalise, or --export.');

        return self::FAILURE;
    }

    /** Phase 1: measure one acquired candidate and decide every criterion decidable alone. */
    private function screenCandidate(string $fullName, SuiteDiscovery $discovery, TrunkMajorWalk $walk): int
    {
        $repository = Repository::where('full_name', $fullName)->first();
        $root = (string) $repository?->clone_path;
        if ($repository === null || ! is_dir($root)) {
            $this->error('Screening needs the clone: run analyse:acquire first.');

            return self::FAILURE;
        }

        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        // laravel/framework must be a direct dependency; illuminate/support alone is not
        // accepted (it appears in packages that merely use the framework's components).
        $frameworkConstraint = $require['laravel/framework'] ?? null;
        $supportConstraint = $require['illuminate/support'] ?? null;
        $dependencyOk = is_string($frameworkConstraint) ? 'pass' : 'fail';
        $recordedConstraint = is_string($frameworkConstraint)
            ? $frameworkConstraint
            : (is_string($supportConstraint) ? "illuminate/support only: {$supportConstraint}" : null);

        $representatives = $walk->representatives($root);
        $majorsCount = count($representatives);

        // Same router as extraction: at least one file at HEAD must route to a front end.
        $files = $discovery->discover(new WorkingTree($root));
        $routedFiles = $this->countRoutedFiles($root, $files);

        // Not the framework core or a package: composer's type field, plus the core repo by
        // name. An absent type technically defaults to "library" in composer semantics, but
        // many real applications omit it, so only an EXPLICIT library declaration fails.
        $composerType = is_array($composer) && is_string($composer['type'] ?? null) ? $composer['type'] : null;
        $packageOk = ($fullName === 'laravel/framework' || $composerType === 'library') ? 'fail' : 'pass';

        [$forkOk, $forkAheadBy] = $this->forkCriterion($repository);

        $proportions = $this->measureProportions($root, $files);

        $rootSha = trim(Process::path($root)
            ->run(['git', 'rev-list', '--max-parents=0', '--first-parent', 'HEAD'])
            ->throw()
            ->output());

        $candidate = Candidate::updateOrCreate(
            ['full_name' => $fullName],
            [
                'repository_id' => $repository->id,
                'dependency_ok' => $dependencyOk,
                'majors_ok' => $majorsCount >= 3 ? 'pass' : 'fail',
                'suite_ok' => $routedFiles > 0 ? 'pass' : 'fail',
                'cloneable_ok' => 'pass', // screening runs on the acquired full clone
                'package_ok' => $packageOk,
                'fork_ok' => $forkOk,
                'file_proportion_ok' => 'pending', // pool-relative: decided by --finalise
                'loc_proportion_ok' => 'pending',
                'framework_constraint' => $recordedConstraint,
                'composer_type' => $composerType,
                'majors_count' => $majorsCount,
                'suite_routed_files' => $routedFiles,
                'fork_ahead_by' => $forkAheadBy,
                'root_commit_sha' => $rootSha,
                'verdict' => 'pending',
                'verdict_computed_at' => null,
                'screened_at' => now(),
                ...$proportions,
            ],
        );

        $this->detectSharedHistory($candidate, $root);
        $candidate->refresh(); // load column defaults (manual_ok) and any shared-history flag

        $this->info("Screened {$fullName} (phase 1; proportions decided at --finalise).");
        $this->table(['criterion', 'outcome', 'measured'], [
            ['laravel/framework direct dependency', $candidate->dependency_ok, (string) $candidate->framework_constraint],
            ['>= 3 Laravel majors on trunk', $candidate->majors_ok, "{$candidate->majors_count} majors"],
            ['PHPUnit or Pest suite at HEAD', $candidate->suite_ok, "{$candidate->suite_routed_files} files route"],
            ['history fully cloneable', $candidate->cloneable_ok, 'full clone acquired'],
            ['not framework core or package', $candidate->package_ok, 'type: '.($candidate->composer_type ?? '(absent)')],
            ['not a fork without independent history', $candidate->fork_ok, $repository->is_fork ? "fork, ahead by {$candidate->fork_ahead_by}" : 'not a fork'],
            ['test file proportion (pool-relative)', $candidate->file_proportion_ok, sprintf('%.2f%% (%d of %d files)', $candidate->test_file_proportion * 100, $candidate->test_file_count, $candidate->php_file_count)],
            ['test LOC proportion (pool-relative)', $candidate->loc_proportion_ok, sprintf('%.2f%% (%d of %d lines)', $candidate->test_loc_proportion * 100, $candidate->test_line_count, $candidate->php_line_count)],
            ['not tutorial/coursework/demo (manual)', $candidate->manual_ok, $candidate->manual_reason ?? '(unanswered)'],
        ]);

        if ($candidate->shared_history_with !== null) {
            $this->warn("Shares history with {$candidate->shared_history_with} (diverged at {$candidate->shared_divergence_sha}) — both flagged for the manual gate.");
        }

        return self::SUCCESS;
    }

    /** @param list<string> $files */
    private function countRoutedFiles(string $root, array $files): int
    {
        $router = new FrontEndRouter;
        $tree = new WorkingTree($root);
        $routed = 0;

        foreach ($files as $relativePath) {
            $source = $tree->read($relativePath);
            if ($source === null) {
                continue;
            }
            try {
                if ($router->route($source) !== null) {
                    $routed++;
                }
            } catch (ParseError) {
                // Screening only asks whether a measurable suite exists; unparseable files
                // are extraction's concern and are recorded there.
            }
        }

        return $routed;
    }

    /**
     * Raw values behind the pool-relative criteria. Test files are the suite-discovered
     * set — the same rule extraction measures by. The denominator is every .php file in the
     * working tree, minus an EXCLUSION list (never an inclusion list, which would quietly
     * miss unconventional layouts): vendor/ and node_modules/ (dependencies are not the
     * project's own code), *.blade.php (templates), and any .php under a lang/ directory at
     * any depth — translation files are PHP arrays of strings, data rather than code, and
     * counting them would measure how many languages a project supports.
     *
     * @param  list<string>  $files
     * @return array<string, int|float>
     */
    private function measureProportions(string $root, array $files): array
    {
        $testFiles = 0;
        $testLines = 0;
        foreach ($files as $relativePath) {
            $path = $root.'/'.$relativePath;
            if (! is_file($path)) {
                continue;
            }
            $testFiles++;
            $testLines += count(file($path) ?: []);
        }

        $phpFiles = 0;
        $phpLines = 0;
        $finder = Finder::create()->files()->in($root)->name('*.php')
            ->notName('*.blade.php')
            ->exclude(['vendor', 'node_modules'])
            ->notPath('#(^|/)lang/#')
            ->ignoreDotFiles(true)->ignoreVCS(true)->ignoreUnreadableDirs();
        foreach ($finder as $file) {
            $phpFiles++;
            $phpLines += count(file($file->getPathname()) ?: []);
        }

        return [
            'test_file_count' => $testFiles,
            'php_file_count' => $phpFiles,
            'test_file_proportion' => $phpFiles > 0 ? $testFiles / $phpFiles : 0.0,
            'test_line_count' => $testLines,
            'php_line_count' => $phpLines,
            'test_loc_proportion' => $phpLines > 0 ? $testLines / $phpLines : 0.0,
        ];
    }

    /**
     * The fork criterion: not being a fork passes outright; a fork passes only with commits
     * of its own beyond the fork point (GitHub's compare API, ahead_by). API-degraded cases
     * stay pending rather than silently passing.
     *
     * @return array{0: string, 1: ?int}
     */
    private function forkCriterion(Repository $repository): array
    {
        if ($repository->is_fork === null) {
            return ['pending', null]; // the API was unreachable at acquisition
        }
        if (! $repository->is_fork) {
            return ['pass', null];
        }

        $parent = $repository->fork_parent;
        if ($parent === null) {
            return ['pending', null];
        }

        try {
            $parentBranch = Http::acceptJson()->get("https://api.github.com/repos/{$parent}")->json('default_branch');
            $head = str_replace('/', ':', (string) $repository->full_name);
            $aheadBy = Http::acceptJson()
                ->get("https://api.github.com/repos/{$parent}/compare/{$parentBranch}...{$head}")
                ->json('ahead_by');
        } catch (ConnectionException) {
            return ['pending', null];
        }

        if (! is_int($aheadBy)) {
            return ['pending', null];
        }

        return [$aheadBy > 0 ? 'pass' : 'fail', $aheadBy];
    }

    /**
     * §3.6 — two candidates that both have independent development but share a common past
     * (crater/InvoiceShelf, october/winter). Identical root commits are the signal: commit
     * SHAs are content-addressed, so a shared root means shared early history. Both halves
     * are flagged for the manual gate with the other's name and the divergence point;
     * neither is auto-excluded — which one to keep is the operator's judgement.
     */
    private function detectSharedHistory(Candidate $candidate, string $root): void
    {
        $partners = Candidate::query()
            ->where('full_name', '!=', $candidate->full_name)
            ->where('root_commit_sha', $candidate->root_commit_sha)
            ->whereNotNull('root_commit_sha')
            ->get();

        foreach ($partners as $partner) {
            $partnerRoot = (string) $partner->repository?->clone_path;
            $divergence = is_dir($partnerRoot) ? $this->divergencePoint($root, $partnerRoot) : null;

            $candidate->update(['shared_history_with' => $partner->full_name, 'shared_divergence_sha' => $divergence]);
            $partner->update(['shared_history_with' => $candidate->full_name, 'shared_divergence_sha' => $divergence]);
        }
    }

    /** The last first-parent commit the two clones share, walking both from the root. */
    private function divergencePoint(string $rootA, string $rootB): ?string
    {
        $listA = $this->firstParentShas($rootA);
        $listB = $this->firstParentShas($rootB);

        $last = null;
        foreach ($listA as $i => $sha) {
            if (! isset($listB[$i]) || $listB[$i] !== $sha) {
                break;
            }
            $last = $sha;
        }

        return $last;
    }

    /** @return list<string> */
    private function firstParentShas(string $root): array
    {
        $output = Process::path($root)
            ->run(['git', 'rev-list', '--first-parent', '--reverse', 'HEAD'])
            ->throw()
            ->output();

        return array_values(array_filter(explode("\n", trim($output))));
    }

    /** §3.3 — the human judgement is recorded, never inferred. */
    private function recordManual(string $fullName): int
    {
        $decision = (string) $this->option('manual');
        if (! in_array($decision, ['include', 'exclude'], true)) {
            $this->error('--manual must be include or exclude.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--manual requires --reason: the judgement is published, so it must be justified.');

            return self::FAILURE;
        }

        $candidate = Candidate::where('full_name', $fullName)->first();
        if ($candidate === null) {
            $this->error('Screen the candidate first — the manual judgement annotates a measured row.');

            return self::FAILURE;
        }

        $candidate->update([
            'manual_decision' => $decision,
            'manual_ok' => $decision === 'include' ? 'pass' : 'fail',
            'manual_reason' => $reason,
            'manual_decided_at' => now(),
        ]);

        $this->info("Recorded manual {$decision} for {$fullName}: {$reason}");

        return self::SUCCESS;
    }

    /**
     * Phase 2 — compute the pool quartiles, apply them, and write every candidate's final
     * verdict. Re-running after the pool changes revises earlier verdicts by design.
     */
    private function finalise(): int
    {
        $pool = Candidate::all()->filter(fn (Candidate $c): bool => $c->passesPhaseOne())->values();

        if ($pool->count() < self::MINIMUM_FINALISE_POOL) {
            $this->error(sprintf(
                'Refusing to finalise: %d candidate(s) pass phase 1, and a first quartile over fewer than %d is degenerate — the pool-relative criteria would be decided by one or two repositories. Screen more candidates first.',
                $pool->count(),
                self::MINIMUM_FINALISE_POOL,
            ));

            return self::FAILURE;
        }

        $fileQ1 = $this->firstQuartile($pool->map(fn (Candidate $c): ?float => $c->test_file_proportion)->all());
        $locQ1 = $this->firstQuartile($pool->map(fn (Candidate $c): ?float => $c->test_loc_proportion)->all());

        $this->info(sprintf(
            'Pool of %d phase-1-passing candidates. First quartiles: test file proportion %.4f (%.2f%%), test LOC proportion %.4f (%.2f%%).',
            $pool->count(),
            $fileQ1,
            $fileQ1 * 100,
            $locQ1,
            $locQ1 * 100,
        ));

        $rows = [];
        foreach (Candidate::orderBy('full_name')->get() as $candidate) {
            $fileOk = $candidate->test_file_proportion !== null
                ? ($candidate->test_file_proportion >= $fileQ1 ? 'pass' : 'fail')
                : 'pending';
            $locOk = $candidate->test_loc_proportion !== null
                ? ($candidate->test_loc_proportion >= $locQ1 ? 'pass' : 'fail')
                : 'pending';

            $outcomes = [
                $candidate->dependency_ok, $candidate->majors_ok, $candidate->suite_ok,
                $candidate->cloneable_ok, $candidate->package_ok, $candidate->fork_ok,
                $fileOk, $locOk, $candidate->manual_ok,
            ];

            // Any failure excludes; otherwise anything unanswered — including the manual
            // criterion — leaves the candidate pending. Silence must not become assent.
            $verdict = in_array('fail', $outcomes, true)
                ? 'excluded'
                : (in_array('pending', $outcomes, true) ? 'pending' : 'included');

            $candidate->update([
                'file_proportion_ok' => $fileOk,
                'loc_proportion_ok' => $locOk,
                'verdict' => $verdict,
                'verdict_computed_at' => now(),
            ]);

            $rows[] = [$candidate->full_name, $fileOk, $locOk, $candidate->manual_ok, $verdict];
        }

        $this->table(['candidate', 'file proportion', 'LOC proportion', 'manual', 'verdict'], $rows);

        return $this->writeCorpusList((string) $this->option('corpus'));
    }

    /**
     * First quartile by linear interpolation between order statistics (R's default, type 7):
     * h = (n-1)/4; Q1 = x[floor(h)] + (h - floor(h)) * (x[floor(h)+1] - x[floor(h)]).
     *
     * @param  array<float|null>  $values
     */
    private function firstQuartile(array $values): float
    {
        $values = array_values(array_filter($values, fn ($v): bool => $v !== null));
        sort($values);

        $h = (count($values) - 1) / 4;
        $lower = (int) floor($h);
        $upper = min($lower + 1, count($values) - 1);

        return $values[$lower] + ($h - $lower) * ($values[$upper] - $values[$lower]);
    }

    /**
     * §3.4 — the corpus list is generated, never typed: hand-copying names from a CSV is
     * exactly how a repository the log excludes ends up in the corpus.
     */
    private function writeCorpusList(string $path): int
    {
        $included = Candidate::where('verdict', 'included')->orderBy('full_name')->pluck('full_name');

        $lines = [
            '# Generated by analyse:screen --finalise on '.now()->toDateTimeString().' — do not edit by hand.',
            '# '.$included->count().' candidate(s) with verdict=included.',
            ...$included->all(),
        ];
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        file_put_contents($absolute, implode("\n", $lines)."\n");

        $this->info("Wrote {$included->count()} included candidate(s) to {$path}.");

        return self::SUCCESS;
    }

    /** §3.4 — the published artefact: readable without the database. */
    private function exportCsv(string $path): int
    {
        $columns = [
            'full_name', 'verdict', 'verdict_computed_at', 'screened_at',
            'dependency_ok', 'majors_ok', 'suite_ok', 'cloneable_ok', 'package_ok',
            'fork_ok', 'file_proportion_ok', 'loc_proportion_ok', 'manual_ok',
            'framework_constraint', 'composer_type', 'majors_count', 'suite_routed_files',
            'fork_ahead_by', 'test_file_count', 'php_file_count', 'test_file_proportion',
            'test_line_count', 'php_line_count', 'test_loc_proportion',
            'root_commit_sha', 'shared_history_with', 'shared_divergence_sha',
            'manual_decision', 'manual_reason', 'manual_decided_at',
        ];

        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        $handle = fopen($absolute, 'w');
        if ($handle === false) {
            $this->error("Cannot write {$absolute}.");

            return self::FAILURE;
        }

        fputcsv($handle, $columns, escape: '');
        foreach (Candidate::orderBy('full_name')->get() as $candidate) {
            fputcsv($handle, array_map(
                fn (string $column): string => (string) $candidate->getAttribute($column),
                $columns,
            ), escape: '');
        }
        fclose($handle);

        $this->info('Exported '.Candidate::count()." candidate(s) to {$absolute}.");

        return self::SUCCESS;
    }
}
