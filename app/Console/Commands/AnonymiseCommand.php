<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Anonymisation\AliasMap;
use App\Analysis\Anonymisation\VerificationGate;
use App\Analysis\Reporting\DatasetQueries;
use App\Models\Candidate;
use App\Models\Repository;
use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The dissertation's data-availability artefact: read a directory of raw analyse:report
 * --export output and write an anonymised copy that is safe to publish. The raw exports stay
 * untouched; the copy is a new directory.
 *
 * The design decision this command implements: the corpus list and the measurements are
 * separable, and only the join between them is withheld. corpus.txt and candidates.txt pass
 * through named and unchanged (they are the study's input — without them nothing is
 * reproducible, and they carry no measurement); every measurement-bearing export is aliased
 * to R01..; and the alias map itself is written locally but NOT published unless --emit-map
 * is passed. A reader who wants to know an alias's identity has to clone the corpus and
 * re-run the analyser — which is reproduction, not a leak.
 *
 * After writing every output file the whole output directory is re-read by the verification
 * gate (App\Analysis\Anonymisation\VerificationGate) and the run fails — deleting the
 * output directory so a partial unsafe copy cannot be published by accident — if any
 * repository name, commit SHA, URL, or email survives outside an explicitly listed
 * exemption. Files present in --source with no transformation rule are skipped, never
 * copied: an allowlist, so a future export cannot leak by default.
 *
 * The alias map is saved immediately after assignment rather than at the end of the run, so
 * a gate failure cannot orphan an assignment; first-run assignment is deterministic
 * (ascending full_name), so the two orderings are behaviourally equivalent.
 *
 * --gitignore and --repo-root are test seams: they default to the repository root's paths
 * and exist so the test suite can exercise --harden and --scan-repo against scratch files
 * instead of the working repository.
 */
class AnonymiseCommand extends Command
{
    protected $signature = 'analyse:anonymise
        {--source=exports : directory holding the raw analyse:report --export output}
        {--out=exports-public : directory to write the anonymised copy into}
        {--map=storage/app/alias_map.csv : alias map; read if present, extended if not}
        {--emit-map : also copy the alias map into --out (default: do not)}
        {--appendix : additionally write the appendix corpus table}
        {--readme : write README.md describing the artefact and what is withheld}
        {--prune : after a successful run, delete the raw exports from the working tree}
        {--scan-repo : also run the gate over every tracked file, reporting without failing}
        {--harden : add the missing root-level artefact paths to .gitignore}
        {--force : replace a non-empty --out that this command did not write}
        {--gitignore=.gitignore : the .gitignore --harden appends to (test seam)}
        {--repo-root= : the working tree --scan-repo walks (test seam; default: base_path)}';

    protected $description = 'Write an anonymised, publishable copy of the raw export directory, verified by the gate';

    /** Marker written into --out so a later run can tell the directory is command-owned. */
    private const string MARKER = '.anonymise-manifest';

    /** Files that carry no identifying data: copied byte-for-byte and asserted unchanged. */
    private const array PASSTHROUGH = [
        'run1_descriptives.csv',
        'run1_descriptive_fit.csv',
        'run1_trend.csv',
        'run1_types_by_version.csv',
        'run1_types_by_window.csv',
        'run1_paradigm_test.csv',
        'run1_paradigm_by_major.csv',
        'run1_sensitivity.csv',
        'run1_ai_comparison.csv',
        'run1_agent_comparison.csv',
        'run1_multiplicity.csv',
        'run1_provenance.csv',
        'type_sample_labeled_clean_confusion.csv',
    ];

    /** The study's input lists: published named and unchanged, by design. */
    private const array NAMED_BY_DESIGN = ['corpus.txt', 'candidates.txt'];

    /**
     * The known screening reason strings, hardcoded rather than learned from the column
     * (a value cannot vouch for itself). Anything else in manual_reason is free text and
     * is redacted.
     */
    private const array KNOWN_MANUAL_REASONS = [
        'real application, actively developed',
        'not actively maintained',
        'no test suite',
        'fails criteria',
        'spans less than 3 majors',
        'composer.json missing',
    ];

    /**
     * One known reason embeds a repository name; it is rewritten to embed the alias instead
     * of being redacted, because fork lineage is a finding that must survive.
     */
    private const string SHARED_HISTORY_REASON = 'no test suite and shared history with ';

    private const array KNOWN_SCREENING_NOTES = [
        'dependency, package: composer.json missing or unreadable at the clone root',
    ];

    private const string REDACTED = '(redacted free text)';

    private const array HARDEN_LINES = [
        '/corpus.txt',
        '/candidates.txt',
        '/decision-log.csv',
        '/decisionlog.csv',
        '/*.log',
        '/exports/',
        '/exports-public/',
        '/storage/app/alias_map.csv',
    ];

    /** @var list<array{string, string, string}> file, action, notes — the run summary */
    private array $summary = [];

    /** @var array<string, mixed> gate exemptions: relative path => scope */
    private array $exemptions = [];

    /** @var array<string, string> relative path => why it is exempt */
    private array $exemptionReasons = [];

    public function handle(): int
    {
        $source = $this->resolvePath((string) $this->option('source'));
        $out = $this->resolvePath((string) $this->option('out'));
        $mapPath = $this->resolvePath((string) $this->option('map'));

        if (! is_dir($source)) {
            $this->error("Source directory not found: {$source}");

            return self::FAILURE;
        }
        if ($this->samePath($source, $out)) {
            $this->error('--source and --out resolve to the same directory; refusing.');

            return self::FAILURE;
        }

        if ((bool) $this->option('harden')) {
            $this->harden($this->resolvePath((string) $this->option('gitignore')));
        }

        $map = AliasMap::load($mapPath);
        $assigned = $map->extend(...$this->aliasUniverse($source));
        $map->save($mapPath);
        $this->line(sprintf(
            '  alias map: %d name(s) mapped (%d newly assigned) — written to %s',
            count($map->fullNames()),
            count($assigned),
            $mapPath,
        ));

        if (! $this->prepareOutputDirectory($out)) {
            return self::FAILURE;
        }

        foreach (File::files($source) as $file) {
            $name = $file->getFilename();
            $ok = match (true) {
                in_array($name, self::NAMED_BY_DESIGN, true) => $this->copyNamedByDesign($source, $out, $name),
                in_array($name, self::PASSTHROUGH, true) => $this->copyPassthrough($source, $out, $name),
                $name === 'decisionlog.csv' => $this->transformDecisionLog($source, $out, $map),
                $name === 'run1_attrition.csv',
                $name === 'run1_agent_prevalence.csv' => $this->transformRepositoryColumn($source, $out, $name, $map),
                $name === 'run1_trend_repositories.csv' => $this->transformTrendRepositories($source, $out, $map),
                $name === 'type_sample_labeled_clean.csv' => $this->transformTypeSample($source, $out, $map),
                default => $this->skipUnknown($name),
            };
            if (! $ok) {
                return $this->abort($out);
            }
        }

        // The artefact is incomplete without the corpus lists and the decision log; their
        // absence from --source must be visible, not silent — assemble them into --source
        // (analyse:screen --finalise --export writes the log) and re-run.
        foreach ([...self::NAMED_BY_DESIGN, 'decisionlog.csv'] as $expected) {
            if (! is_file("{$source}/{$expected}")) {
                $this->summary[] = [$expected, 'MISSING', 'not in --source — the published artefact is incomplete without it'];
                $this->warn("{$expected} is not in --source; the artefact will not be reproducible without it.");
            }
        }

        if ((bool) $this->option('emit-map')) {
            File::copy($mapPath, "{$out}/alias_map.csv");
            $this->registerExemption('alias_map.csv', '*', 'RE-IDENTIFICATION KEY (--emit-map)');
            $this->summary[] = ['alias_map.csv', 'emitted', 'EXEMPT from gate — RE-IDENTIFICATION KEY'];
            $this->warn('WARNING: --emit-map copied the alias map into the output directory.');
            $this->warn('The published files can now be tied back to named repositories. Publish it only if that is the intent.');
        }

        $this->reportMethodReconciliation($source);

        if ((bool) $this->option('appendix') && ! $this->writeAppendix($source, $out, $map)) {
            return $this->abort($out);
        }
        if ((bool) $this->option('readme')) {
            $this->writeReadme($source, $out);
        }

        $this->writeMarker($out);

        $gate = new VerificationGate($map->fullNames(), $map->segments());
        $findings = $gate->scan($out, $this->exemptions);

        $this->components->info('Run summary');
        $this->table(['file', 'action', 'notes'], $this->summary);

        if ($findings->isNotEmpty()) {
            $this->table(
                ['file', 'line', 'rule', 'matched text'],
                $findings->map(fn (array $f): array => [
                    $f['file'], $f['line'], VerificationGate::RULE_LABELS[$f['rule']], $f['match'],
                ])->all(),
            );
            $this->error(sprintf(
                'GATE FAILED: %d finding(s) — the output directory has been deleted so a partial unsafe copy cannot be published.',
                $findings->count(),
            ));

            return $this->abort($out);
        }

        $exempt = array_map(
            fn (string $path, string $reason): string => "{$path} ({$reason})",
            array_keys($this->exemptionReasons),
            $this->exemptionReasons,
        );
        $this->info(sprintf(
            'gate: PASS — 4 rules over %d file(s), %d exemption(s)%s',
            count(File::allFiles($out)),
            count($this->exemptions),
            $exempt === [] ? '' : ': '.implode('; ', $exempt),
        ));

        if ((bool) $this->option('scan-repo')) {
            $this->scanRepo($gate, $out);
        }

        if ((bool) $this->option('prune') && ! $this->prune($source, $out, $mapPath)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ── Alias universe ─────────────────────────────────────────────────────────────────

    /**
     * Every screened candidate needs an alias — the decision log covers excluded candidates
     * too, and a shared_history_with counterpart needs one even if it was never screened.
     * Ids come from the repositories table (excluded candidates without a clone have none).
     *
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private function aliasUniverse(string $source): array
    {
        $names = Candidate::query()->pluck('full_name')
            ->merge(Repository::query()->pluck('full_name'));

        $log = "{$source}/decisionlog.csv";
        if (is_file($log) && ($csv = $this->readCsv($log, ['full_name'])) !== null) {
            $fullName = $csv['index']['full_name'];
            $shared = array_search('shared_history_with', $csv['header'], true);
            foreach ($csv['rows'] as $row) {
                $names->push((string) ($row[$fullName] ?? ''));
                if ($shared !== false && ($row[$shared] ?? '') !== '') {
                    $names->push((string) $row[$shared]);
                }
            }
        }

        /** @var array<string, int> $ids */
        $ids = Repository::query()->pluck('id', 'full_name')->all();

        $unique = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if ($name !== '' && ! in_array($name, $unique, true)) {
                $unique[] = $name;
            }
        }

        return [$unique, $ids];
    }

    // ── Output directory ───────────────────────────────────────────────────────────────

    /**
     * --out is wiped and recreated, but only when it is demonstrably this command's own
     * output (it carries the marker file) or the operator passed --force. A typo like
     * --out=app must not delete an arbitrary directory.
     */
    private function prepareOutputDirectory(string $out): bool
    {
        if (is_dir($out) && File::allFiles($out) !== []) {
            if (! is_file($out.'/'.self::MARKER) && ! (bool) $this->option('force')) {
                $this->error("Output directory {$out} is not empty and does not look like a previous run of this command (no ".self::MARKER.'). Refusing to delete it; pass --force to override.');

                return false;
            }
            File::deleteDirectory($out);
            $this->line("  replaced existing output directory: {$out}");
        }

        File::ensureDirectoryExists($out);

        return true;
    }

    private function writeMarker(string $out): void
    {
        $files = array_map(
            fn (\SplFileInfo $f): string => $f->getFilename(),
            File::files($out),
        );
        sort($files);
        File::put(
            $out.'/'.self::MARKER,
            'written by analyse:anonymise at '.now()->toIso8601String()."\n".implode("\n", $files)."\n",
        );
    }

    private function abort(string $out): int
    {
        File::deleteDirectory($out);

        return self::FAILURE;
    }

    // ── Per-file handlers ──────────────────────────────────────────────────────────────

    private function copyNamedByDesign(string $source, string $out, string $name): bool
    {
        File::copy("{$source}/{$name}", "{$out}/{$name}");
        $this->registerExemption($name, '*', 'named by design');
        $this->summary[] = [$name, 'passthrough', 'EXEMPT from gate — named by design; the corpus list is the study\'s input'];

        return true;
    }

    private function copyPassthrough(string $source, string $out, string $name): bool
    {
        File::copy("{$source}/{$name}", "{$out}/{$name}");
        if (hash_file('sha256', "{$source}/{$name}") !== hash_file('sha256', "{$out}/{$name}")) {
            $this->error("{$name}: the copied file differs from its source — refusing to publish a silently altered passthrough.");

            return false;
        }
        $this->summary[] = [$name, 'copied', 'byte-identity verified'];

        return true;
    }

    private function skipUnknown(string $name): bool
    {
        $this->summary[] = [$name, 'skipped', 'not in the publication inventory — never copied by default'];

        return true;
    }

    /**
     * The decision log carries more identifiers than the name column: commit SHAs (dropped —
     * a SHA is a unique search key), the shared-history counterpart (aliased, because fork
     * lineage is a finding), and two free-text columns passed through only on an exact match
     * against the hardcoded known strings.
     */
    private function transformDecisionLog(string $source, string $out, AliasMap $map): bool
    {
        $csv = $this->readCsv("{$source}/decisionlog.csv", ['full_name', 'root_commit_sha', 'shared_divergence_sha', 'shared_history_with', 'manual_reason', 'screening_notes', 'composer_type']);
        if ($csv === null) {
            return false;
        }
        $index = $csv['index'];
        $drop = [$index['root_commit_sha'], $index['shared_divergence_sha']];
        $segments = array_map(strtolower(...), $map->segments());

        $redactions = ['manual_reason' => 0, 'screening_notes' => 0];
        $manifestTypes = [];
        $rows = [];
        foreach ($csv['rows'] as $row) {
            $alias = $map->aliasFor((string) $row[$index['full_name']]);
            if ($alias === null) {
                $this->error("decisionlog.csv: no alias for {$row[$index['full_name']]}.");

                return false;
            }
            $row[$index['full_name']] = $alias;

            $shared = (string) ($row[$index['shared_history_with']] ?? '');
            if ($shared !== '') {
                $sharedAlias = $map->aliasFor($shared);
                if ($sharedAlias === null) {
                    $this->error("decisionlog.csv: no alias for shared_history_with counterpart {$shared}.");

                    return false;
                }
                $row[$index['shared_history_with']] = $sharedAlias;
            }

            $row[$index['manual_reason']] = $this->cleanReason((string) ($row[$index['manual_reason']] ?? ''), $map, $redactions['manual_reason']);
            $notes = (string) ($row[$index['screening_notes']] ?? '');
            if ($notes !== '' && ! in_array($notes, self::KNOWN_SCREENING_NOTES, true)) {
                $redactions['screening_notes']++;
                $notes = self::REDACTED;
            }
            $row[$index['screening_notes']] = $notes;

            // A composer "type" of e.g. `cms` can collide with a repository's own segment
            // (LavaLite/cms). It is a manifest package type, not a repository reference, so
            // it earns a value-scoped gate exemption — the value alone, never a full
            // owner/repo, which would still fail the gate if planted here.
            $type = (string) ($row[$index['composer_type']] ?? '');
            if ($type !== '' && ! str_contains($type, '/') && in_array(strtolower($type), $segments, true)) {
                $manifestTypes[] = $type;
            }

            $rows[] = $this->dropColumns($row, $drop);
        }

        $notes = sprintf(
            '%d row(s) aliased; 2 SHA columns dropped; %d manual_reason + %d screening_notes cell(s) redacted',
            count($rows),
            $redactions['manual_reason'],
            $redactions['screening_notes'],
        );
        if ($manifestTypes !== []) {
            $manifestTypes = array_values(array_unique($manifestTypes));
            $this->registerExemption(
                'decisionlog.csv',
                ['values' => $manifestTypes],
                'composer_type value(s) '.implode(', ', $manifestTypes).' — manifest package types, not repository references',
            );
            $notes .= '; EXEMPT values: '.implode(', ', $manifestTypes).' (composer_type)';
        }

        $this->writeCsv("{$out}/decisionlog.csv", $this->dropColumns($csv['header'], $drop), $rows);
        $this->summary[] = ['decisionlog.csv', 'transformed', $notes];

        return true;
    }

    private function cleanReason(string $reason, AliasMap $map, int &$redactions): string
    {
        if ($reason === '' || in_array($reason, self::KNOWN_MANUAL_REASONS, true)) {
            return $reason;
        }

        if (str_starts_with($reason, self::SHARED_HISTORY_REASON)) {
            $alias = $map->aliasFor(substr($reason, strlen(self::SHARED_HISTORY_REASON)));
            if ($alias !== null) {
                return self::SHARED_HISTORY_REASON.$alias;
            }
        }

        $redactions++;

        return self::REDACTED;
    }

    private function transformRepositoryColumn(string $source, string $out, string $name, AliasMap $map): bool
    {
        $csv = $this->readCsv("{$source}/{$name}", ['repository']);
        if ($csv === null) {
            return false;
        }
        $column = $csv['index']['repository'];

        $rows = [];
        $aliased = 0;
        foreach ($csv['rows'] as $row) {
            $value = (string) ($row[$column] ?? '');
            if ($value !== '(all)') {
                $alias = $map->aliasFor($value);
                if ($alias === null) {
                    $this->error("{$name}: no alias for repository {$value}.");

                    return false;
                }
                $row[$column] = $alias;
                $aliased++;
            }
            $rows[] = $row;
        }

        $this->writeCsv("{$out}/{$name}", $csv['header'], $rows);
        $this->summary[] = [$name, 'transformed', sprintf('%d repository cell(s) aliased', $aliased)];

        return true;
    }

    /**
     * This export keys on the numeric repositories-table id. An unmapped id fails the run —
     * a silently unmapped id would publish a pseudonym that matches nothing else.
     */
    private function transformTrendRepositories(string $source, string $out, AliasMap $map): bool
    {
        $csv = $this->readCsv("{$source}/run1_trend_repositories.csv", ['repository_id']);
        if ($csv === null) {
            return false;
        }
        $column = $csv['index']['repository_id'];

        $rows = [];
        foreach ($csv['rows'] as $row) {
            $alias = $map->aliasForId((int) ($row[$column] ?? 0));
            if ($alias === null) {
                $this->error("run1_trend_repositories.csv: repository_id {$row[$column]} is not in the alias map — failing rather than publishing an unmatched pseudonym.");

                return false;
            }
            $row[$column] = $alias;
            $rows[] = $row;
        }

        $this->writeCsv("{$out}/run1_trend_repositories.csv", $csv['header'], $rows);
        $this->summary[] = ['run1_trend_repositories.csv', 'transformed', sprintf('%d id(s) aliased', count($rows))];

        return true;
    }

    /**
     * The riskiest file: 100 verbatim third-party source excerpts. The published copy keeps
     * only id, aliased repository, human_label, and a classifier_label read back from the
     * database (it is deliberately absent from the CSV so the labeller stayed blind) — a
     * file that reproduces the validation result (Fig. B8, kappa) and nothing else. The
     * derived label pairs are then cross-tabulated against the published confusion matrix,
     * so a database that has drifted since the analysis run cannot silently publish labels
     * the matrix contradicts.
     */
    private function transformTypeSample(string $source, string $out, AliasMap $map): bool
    {
        $csv = $this->readCsv("{$source}/type_sample_labeled_clean.csv", ['id', 'repository', 'human_label']);
        if ($csv === null) {
            return false;
        }
        $index = $csv['index'];

        $rows = [];
        $pairs = [];
        foreach ($csv['rows'] as $row) {
            $id = (int) $row[$index['id']];
            $alias = $map->aliasFor((string) $row[$index['repository']]);
            if ($alias === null) {
                $this->error("type_sample_labeled_clean.csv: no alias for repository {$row[$index['repository']]}.");

                return false;
            }
            $classifier = TestObservation::query()->whereKey($id)->value('test_type');
            if ($classifier === null) {
                $this->error("type_sample_labeled_clean.csv: row id {$id} matches no test observation — the classifier label cannot be read back, and the confusion matrix alone cannot recover it.");

                return false;
            }
            $human = strtolower(trim((string) $row[$index['human_label']]));
            $rows[] = [$id, $alias, $human, $classifier];
            $pairs[$human][$classifier] = ($pairs[$human][$classifier] ?? 0) + 1;
        }

        if (! $this->confusionMatrixAgrees($source, $pairs)) {
            return false;
        }

        $this->writeCsv("{$out}/type_sample_labeled_clean.csv", ['id', 'repository', 'human_label', 'classifier_label'], $rows);
        $this->summary[] = ['type_sample_labeled_clean.csv', 'transformed', sprintf(
            '%d row(s); file_path, identifier, source_excerpt dropped; classifier_label added from the database and cross-checked against the confusion matrix',
            count($rows),
        )];

        return true;
    }

    /**
     * @param  array<string, array<string, int>>  $pairs  human => classifier => count
     */
    private function confusionMatrixAgrees(string $source, array $pairs): bool
    {
        $path = "{$source}/type_sample_labeled_clean_confusion.csv";
        if (! is_file($path)) {
            $this->warn('type_sample_labeled_clean_confusion.csv is not in --source — the classifier_label cross-check was skipped.');

            return true;
        }

        $csv = $this->readCsv($path, []);
        if ($csv === null) {
            return false;
        }

        $labels = array_slice($csv['header'], 1);
        $mismatches = [];
        foreach ($csv['rows'] as $row) {
            $human = (string) $row[0];
            foreach ($labels as $offset => $classifier) {
                $published = (int) ($row[$offset + 1] ?? 0);
                $derived = $pairs[$human][$classifier] ?? 0;
                if ($published !== $derived) {
                    $mismatches[] = "human={$human} classifier={$classifier}: matrix says {$published}, database says {$derived}";
                }
            }
        }

        if ($mismatches !== []) {
            $this->error('type_sample_labeled_clean.csv: the classifier labels read from the database no longer reproduce the published confusion matrix — has the dataset been re-mined since the analysis run?');
            foreach ($mismatches as $mismatch) {
                $this->line("  {$mismatch}");
            }

            return false;
        }

        return true;
    }

    // ── Reconciliation, appendix, README ───────────────────────────────────────────────

    /**
     * The two method totals in these exports measure different populations and the README
     * must not quote one as if it were the other: attrition counts observation rows at each
     * repository's newest snapshot; prevalence (and types_by_window) count distinct blamed
     * methods after collapsing duplicate test names within a file. Printed every run so the
     * distinction cannot be forgotten.
     */
    private function reportMethodReconciliation(string $source): void
    {
        $counts = $this->methodCounts($source);
        if ($counts === null) {
            return;
        }
        [$scope, $distinct] = $counts;

        if ($scope > 0 && $distinct > 0 && $scope !== $distinct) {
            $this->line(sprintf(
                '  method counts: %s observation rows in the blame scope (attrition); %s distinct blamed methods '
                .'(prevalence, types_by_window) — the difference is duplicate-named tests collapsed by the '
                .'one-per-method dedup plus unattributed rows, not lost data.',
                number_format($scope),
                number_format($distinct),
            ));
        }
    }

    /**
     * @return array{int, int}|null blame-scope rows, distinct blamed methods
     */
    private function methodCounts(string $source): ?array
    {
        if (! is_file("{$source}/run1_attrition.csv") || ! is_file("{$source}/run1_agent_prevalence.csv")) {
            return null;
        }
        $attrition = $this->readCsv("{$source}/run1_attrition.csv", ['repository', 'blame_scope_methods']);
        $prevalence = $this->readCsv("{$source}/run1_agent_prevalence.csv", ['repository', 'blamed_methods']);
        if ($attrition === null || $prevalence === null) {
            return null;
        }

        $scope = 0;
        foreach ($attrition['rows'] as $row) {
            if (($row[$attrition['index']['repository']] ?? '') === '(all)') {
                $scope = (int) $row[$attrition['index']['blame_scope_methods']];
            }
        }
        $distinct = array_sum(array_map(
            fn (array $row): int => (int) ($row[$prevalence['index']['blamed_methods']] ?? 0),
            $prevalence['rows'],
        ));

        return [$scope, $distinct];
    }

    private function writeAppendix(string $source, string $out, AliasMap $map): bool
    {
        $attrition = $this->readCsv("{$source}/run1_attrition.csv", ['repository', 'blame_scope_methods']);
        if ($attrition === null) {
            $this->error('--appendix needs run1_attrition.csv in --source for the per-repository method counts.');

            return false;
        }
        $prevalence = is_file("{$source}/run1_agent_prevalence.csv")
            ? $this->readCsv("{$source}/run1_agent_prevalence.csv", ['repository', 'blamed_methods', 'agent_traced'])
            : null;

        $traced = [];
        if ($prevalence !== null) {
            foreach ($prevalence['rows'] as $row) {
                $traced[(string) $row[$prevalence['index']['repository']]] = [
                    (int) $row[$prevalence['index']['blamed_methods']],
                    (int) $row[$prevalence['index']['agent_traced']],
                ];
            }
        }

        $checkpoints = DatasetQueries::paradigmCheckpointAggregates()->groupBy('repository_id');
        $idsByName = Repository::query()->pluck('id', 'full_name');
        $paradigms = [0 => 'phpunit', 1 => 'mixed', 2 => 'pest'];

        $rows = [];
        $totals = ['majors' => 0, 'methods' => 0, 'blamed' => 0, 'traced' => 0, 'first' => null, 'last' => null];
        foreach ($attrition['rows'] as $row) {
            $name = (string) $row[$attrition['index']['repository']];
            if ($name === '(all)') {
                continue;
            }
            $alias = $map->aliasFor($name);
            if ($alias === null) {
                $this->error("appendix: no alias for repository {$name}.");

                return false;
            }

            /** @var Collection<int, array{major: int, pf: int}> $series */
            $series = $checkpoints->get($idsByName[$name] ?? -1, new Collection);
            $methods = (int) $row[$attrition['index']['blame_scope_methods']];
            [$blamed, $agentTraced] = $traced[$name] ?? [0, 0];

            $rows[$alias] = [
                $alias,
                $series->count(),
                $series->isEmpty() ? '—' : $series->min('major'),
                $series->isEmpty() ? '—' : $series->max('major'),
                $methods,
                $series->isEmpty() ? '—' : $paradigms[$series->sortBy('major')->last()['pf']],
                $blamed > 0 ? sprintf('%.1f', $agentTraced / $blamed * 100) : '—',
            ];

            $totals['majors'] += $series->count();
            $totals['methods'] += $methods;
            $totals['blamed'] += $blamed;
            $totals['traced'] += $agentTraced;
            if (! $series->isEmpty()) {
                $totals['first'] = min($totals['first'] ?? PHP_INT_MAX, $series->min('major'));
                $totals['last'] = max($totals['last'] ?? 0, $series->max('major'));
            }
        }

        ksort($rows, SORT_STRING);
        $rows = array_values($rows);
        // A rate in the total row is pooled — sum over sum — never a mean of percentages.
        $rows[] = [
            '(all)',
            $totals['majors'],
            $totals['first'] ?? '—',
            $totals['last'] ?? '—',
            $totals['methods'],
            '—',
            $totals['blamed'] > 0 ? sprintf('%.1f', $totals['traced'] / $totals['blamed'] * 100) : '—',
        ];

        $header = ['alias', 'majors_contributed', 'first_major', 'last_major', 'test_methods', 'paradigm_at_head', 'agent_trace_pct'];
        $this->writeCsv("{$out}/appendix_corpus.csv", $header, $rows);

        $markdown = '| '.implode(' | ', $header)." |\n|".str_repeat('---|', count($header))."\n";
        foreach ($rows as $row) {
            $markdown .= '| '.implode(' | ', array_map(strval(...), $row))." |\n";
        }
        File::put("{$out}/appendix_corpus.md", $markdown);

        $this->summary[] = ['appendix_corpus.csv / .md', 'generated', sprintf('%d included repository row(s) + total row', count($rows) - 1)];

        return true;
    }

    private function writeReadme(string $source, string $out): void
    {
        $toolVersion = 'unknown';
        $provenance = $this->readCsv("{$source}/run1_provenance.csv", ['tool_version']);
        if ($provenance !== null && $provenance['rows'] !== []) {
            $toolVersion = (string) $provenance['rows'][0][$provenance['index']['tool_version']];
        }

        $sha = trim(Process::path(base_path())->run(['git', 'rev-parse', 'HEAD'])->output());
        if ($sha === '' || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            $sha = 'unknown';
        }

        $screened = Candidate::query()->count();
        $included = Candidate::query()->where('verdict', 'included')->count();
        $counts = $this->methodCounts($source);
        $observations = TestObservation::query()->count();

        $corpus = $included > 0 || $screened > 0
            ? sprintf(
                '%d candidate repositories were screened and %d were included in the corpus. ',
                $screened,
                $included,
            )
            : '';
        $reconciliation = $counts !== null && $counts[0] > 0
            ? sprintf(
                'The dataset holds %s test-method observations overall; %s observation rows sit in the blame scope '
                .'(each repository\'s newest snapshot, `run1_attrition.csv`), of which %s distinct blamed methods '
                .'reach the prevalence and window tables — the difference is duplicate-named tests collapsed by '
                ."the one-per-method deduplication plus a handful of unattributed rows, not missing data.\n\n",
                number_format($observations),
                number_format($counts[0]),
                number_format($counts[1]),
            )
            : '';

        $readme = <<<MD
        # Anonymised analysis exports

        {$corpus}This directory is the anonymised, publishable copy of the raw `analyse:report --export`
        output, produced by `php artisan analyse:anonymise` at commit `{$sha}` with tool version
        `{$toolVersion}`. {$reconciliation}
        ## How repositories are identified

        Every measurement-bearing file identifies repositories by a stable alias (`R01`, `R02`, ...).
        The alias map joining aliases to repository names is **not** included in this artefact. Withholding
        the map means no measurement or screening judgment here can be attributed to a named project.
        It does not — and is not meant to — prevent anyone from re-deriving a measurement: the corpus
        is open source and fully named in `corpus.txt`.

        ## Reproducibility

        `corpus.txt` and `candidates.txt` are the study's input lists, published **named and unmodified**,
        so the study is reproducible end to end. From a clean checkout of the analyser at the commit above:

            php artisan migrate
            php artisan analyse:batch --screen candidates.txt
            # answer the manual tutorial/demo criterion per candidate (analyse:screen --manual), then:
            php artisan analyse:screen --finalise --export=storage/app/report/decisionlog.csv
            php artisan analyse:batch corpus.txt
            php artisan analyse:report --sensitivity --export=storage/app/report/run1.csv
            php artisan analyse:anonymise --source=storage/app/report --appendix --readme

        A reader who re-runs the pipeline over the named corpus derives every measurement themselves,
        which is precisely what reproducibility means.

        ## What was changed, and why

        - `decisionlog.csv`: repository names replaced by aliases (fork-lineage counterparts aliased,
          not blanked); the two commit-SHA columns dropped (a SHA is a unique search key); free-text
          cells that do not exactly match a known screening reason replaced with `(redacted free text)`.
        - `run1_attrition.csv`, `run1_agent_prevalence.csv`, `run1_trend_repositories.csv`: repository
          keys replaced by aliases.
        - `type_sample_labeled_clean.csv`: the `file_path`, `identifier`, and `source_excerpt` columns
          are **dropped** — paths and test names are searchable, and the excerpts are verbatim third-party
          code. The file is not corrupt: it deliberately keeps only what reproduces the classifier
          validation (Fig. B8 and the kappa statistic), via the added `classifier_label` column.
        - Every other file is copied byte-for-byte and verified unchanged.

        ## Publishing note

        The recommended way to publish this artefact is from a fresh repository or a tagged release
        built from this directory alone, leaving the working repository as-is — nothing to delete,
        no history to rewrite.
        MD;

        File::put("{$out}/README.md", $readme."\n");
        $this->registerExemption('README.md', $sha === 'unknown' ? [] : ['values' => [$sha]], 'provenance commit SHA only');
        $this->summary[] = ['README.md', 'generated', 'EXEMPT from gate for the provenance commit SHA literal only'];
    }

    // ── Hygiene: --harden, --scan-repo, --prune ────────────────────────────────────────

    private function harden(string $gitignore): void
    {
        $existing = is_file($gitignore)
            ? array_map(trim(...), file($gitignore, FILE_IGNORE_NEW_LINES) ?: [])
            : [];

        $added = [];
        foreach (self::HARDEN_LINES as $line) {
            if (! in_array($line, $existing, true)) {
                $added[] = $line;
            }
        }

        if ($added !== []) {
            $suffix = ($existing === [] || end($existing) === '' ? '' : "\n").implode("\n", $added)."\n";
            File::append($gitignore, $suffix);
        }

        $this->components->info('--harden');
        $this->line($added === []
            ? '  nothing to add — every entry is already present.'
            : '  added: '.implode(', ', $added));
        $this->warn('  note: /corpus.txt and /candidates.txt are ignored only while the artefact is being prepared —');
        $this->warn('  the design decision is to PUBLISH them (named corpus lists are the study\'s input). Remove those');
        $this->warn('  lines, and /exports-public/, when the artefact is ready. Ignoring a file does not untrack one');
        $this->warn('  already committed (candidates.txt currently is).');
    }

    private function scanRepo(VerificationGate $gate, string $out): void
    {
        $root = (string) ($this->option('repo-root') ?: base_path());
        $result = Process::path($root)->run(['git', 'ls-files']);
        if (! $result->successful()) {
            $this->warn("--scan-repo: git ls-files failed in {$root} — skipped.");

            return;
        }

        $excluded = ['vendor/', 'node_modules/', 'storage/corpus/'];
        $outRelative = str_starts_with($out, $root.'/') ? substr($out, strlen($root) + 1).'/' : null;

        $findings = [];
        $scanned = 0;
        foreach (array_filter(explode("\n", $result->output())) as $tracked) {
            if (in_array($tracked, self::NAMED_BY_DESIGN, true)) {
                continue;
            }
            foreach ($excluded as $prefix) {
                if (str_starts_with($tracked, $prefix)) {
                    continue 2;
                }
            }
            if ($outRelative !== null && str_starts_with($tracked, $outRelative)) {
                continue;
            }
            $scanned++;
            foreach ($gate->scanFile("{$root}/{$tracked}") as $finding) {
                $findings[] = [$tracked, $finding['line'], VerificationGate::RULE_LABELS[$finding['rule']], $finding['match']];
            }
        }

        $this->components->info('--scan-repo (reports without failing — a hit may be a fixture, a test, or the corpus list)');
        if ($findings === []) {
            $this->line("  {$scanned} tracked file(s) scanned — no findings.");

            return;
        }
        $this->table(['file', 'line', 'rule', 'matched text'], $findings);
        $this->warn(sprintf('  %d finding(s) across %d tracked file(s) — review before any git add.', count($findings), $scanned));
    }

    private function prune(string $source, string $out, string $mapPath): bool
    {
        $realSource = realpath($source);
        $realMap = realpath($mapPath);
        if ($realSource === false) {
            return true;
        }
        if ($realMap !== false && str_starts_with($realMap, $realSource.'/')) {
            $this->error('--prune: the alias map lives inside --source; refusing — the map is the one file that must survive locally.');

            return false;
        }

        $deletable = array_values(array_filter(
            File::allFiles($source),
            fn (\SplFileInfo $f): bool => ! in_array($f->getFilename(), self::NAMED_BY_DESIGN, true),
        ));

        $this->components->info('--prune: the following raw export files will be deleted from the working tree');
        foreach ($deletable as $file) {
            $this->line('  '.$file->getPathname());
        }

        if (! (bool) $this->option('no-interaction') && ! $this->confirm('Delete these files?')) {
            $this->line('  --prune cancelled.');

            return true;
        }

        foreach ($deletable as $file) {
            File::delete($file->getPathname());
        }
        if (File::allFiles($source) === [] && File::directories($source) === []) {
            File::deleteDirectory($source);
        }

        $this->warn('--prune removed files from the WORKING TREE only. Anything already committed remains in git');
        $this->warn('history and stays reachable through the commit graph, tags, and any fork. If no measurement-');
        $this->warn('bearing named file may ever have appeared on the public remote, deletion is not enough:');
        $this->warn('  1. publish an orphan branch (git checkout --orphan public) as the only public branch; or');
        $this->warn('  2. publish a fresh repository containing just the anonymised artefact (recommended); or');
        $this->warn('  3. rewrite history with git filter-repo.');
        $this->warn('This command cannot do any of those and does not pretend to.');

        return true;
    }

    // ── CSV plumbing ───────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $required
     * @return array{header: list<string>, rows: list<list<string|null>>, index: array<string, int>}|null
     */
    private function readCsv(string $path, array $required): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open {$path} for reading.");

            return null;
        }

        $header = fgetcsv($handle, escape: '');
        if ($header === false) {
            fclose($handle);
            $this->error("{$path} is empty.");

            return null;
        }

        $index = [];
        foreach ($required as $column) {
            $position = array_search($column, $header, true);
            if ($position === false) {
                fclose($handle);
                $this->error("{$path} does not carry the expected {$column} column.");

                return null;
            }
            $index[$column] = (int) $position;
        }

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return ['header' => $header, 'rows' => $rows, 'index' => $index];
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function writeCsv(string $path, array $header, iterable $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  list<int>  $drop
     * @return list<mixed>
     */
    private function dropColumns(array $row, array $drop): array
    {
        return array_values(array_filter(
            $row,
            fn (int $column): bool => ! in_array($column, $drop, true),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    private function registerExemption(string $relativePath, mixed $scope, string $reason): void
    {
        $this->exemptions[$relativePath] = $scope;
        $this->exemptionReasons[$relativePath] = $reason;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function samePath(string $a, string $b): bool
    {
        $realA = realpath($a);
        $realB = realpath($b);
        if ($realA !== false && $realB !== false) {
            return $realA === $realB;
        }

        return rtrim($a, '/') === rtrim($b, '/');
    }
}
