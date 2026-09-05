<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\GitFixtureRepo;
use Tests\TestCase;

/**
 * The anonymisation gate and transforms behind the data-availability artefact. The
 * transformation tests prove the published tables are right; the gate tests prove a wrong
 * table cannot leave the building — a transformation bug produces a wrong number, a gate bug
 * produces a published file that identifies the corpus, which is why the planted-leak cases
 * (names, SHAs, a leak beside a legitimately named corpus list) matter most here.
 */
function anonymiseRoot(): string
{
    return base_path('storage/framework/testing/anonymise');
}

function anonymiseSource(): string
{
    return anonymiseRoot().'/exports';
}

function anonymiseOut(): string
{
    return anonymiseRoot().'/exports-public';
}

function anonymiseMap(): string
{
    return anonymiseRoot().'/alias_map.csv';
}

/**
 * @return list<list<string|null>> quote-aware rows, header first
 */
function anonymiseCsvRows(string $path): array
{
    $handle = fopen($path, 'r');
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

/**
 * Two included repositories with one observation each (the type-sample rows), plus their
 * candidate rows. Ascending full_name order is acme/widgets < birch/planks, so a first run
 * assigns R01 and R02.
 *
 * @return array{Repository, Repository, TestObservation, TestObservation}
 */
function seedAnonymiseRepos(): array
{
    $repositories = [];
    $observations = [];
    foreach (['acme/widgets' => 'unit', 'birch/planks' => 'feature'] as $fullName => $type) {
        $repository = Repository::create([
            'full_name' => $fullName,
            'owner' => explode('/', $fullName)[0],
            'name' => explode('/', $fullName)[1],
            'url' => "https://github.com/{$fullName}.git",
        ]);
        Candidate::create(['full_name' => $fullName, 'verdict' => 'included', 'repository_id' => $repository->id]);
        $snapshot = Snapshot::create(['repository_id' => $repository->id, 'commit_sha' => 'fixture', 'kind' => 'head']);
        $observations[] = TestObservation::create([
            'snapshot_id' => $snapshot->id,
            'repository_id' => $repository->id,
            'file_path' => 'tests/Feature/ExampleTest.php',
            'identifier' => 'test_example',
            'front_end' => 'phpunit',
            'test_type' => $type,
        ]);
        $repositories[] = $repository;
    }

    return [...$repositories, ...$observations];
}

/**
 * A miniature but structurally faithful raw export directory. $options: 'shared' maps a
 * full_name to its shared-history counterpart; 'composer_type' overrides that cell per
 * full_name (test 11 plants a leak there); 'manual_reason' overrides per full_name.
 *
 * @param  array{Repository, Repository, TestObservation, TestObservation}  $seeded
 * @param  array<string, mixed>  $options
 */
function makeAnonymiseExportDir(array $seeded, array $options = []): void
{
    [$acme, $birch, $obsAcme, $obsBirch] = $seeded;
    $dir = anonymiseSource();
    File::ensureDirectoryExists($dir);

    $write = function (string $name, array $rows): void {
        $handle = fopen(anonymiseSource()."/{$name}", 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
    };

    $header = [
        'full_name', 'verdict', 'verdict_computed_at', 'screened_at',
        'dependency_ok', 'majors_ok', 'suite_ok', 'cloneable_ok', 'package_ok',
        'fork_ok', 'file_proportion_ok', 'loc_proportion_ok', 'manual_ok',
        'framework_constraint', 'composer_type', 'majors_count', 'suite_routed_files',
        'fork_ahead_by', 'test_file_count', 'php_file_count', 'test_file_proportion',
        'test_line_count', 'php_line_count', 'test_loc_proportion',
        'root_commit_sha', 'shared_history_with', 'shared_divergence_sha',
        'manual_decision', 'manual_reason', 'manual_decided_at', 'screening_notes',
    ];
    $logRows = [$header];
    foreach ([$acme->full_name, $birch->full_name] as $fullName) {
        $logRows[] = [
            $fullName, 'included', '', '',
            'pass', 'pass', 'pass', 'pass', 'pass',
            'pass', 'pass', 'pass', 'pass',
            '^9.0', $options['composer_type'][$fullName] ?? 'project', 3, 5,
            '', 10, 100, 0.1,
            200, 2000, 0.1,
            'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0',
            $options['shared'][$fullName] ?? '',
            $options['shared'][$fullName] ?? '' ? 'd0c9b8a7f6e5d4c3b2a1d0c9b8a7f6e5d4c3b2a1' : '',
            'include', $options['manual_reason'][$fullName] ?? 'real application, actively developed', '', '',
        ];
    }
    $write('decisionlog.csv', $logRows);

    $write('run1_attrition.csv', [
        ['repository', 'blame_scope_methods', 'unattributed', 'unattributed_pct'],
        [$acme->full_name, 2, 0, '0.0'],
        [$birch->full_name, 2, 0, '0.0'],
        ['(all)', 4, 0, '0.0'],
    ]);

    $write('run1_agent_prevalence.csv', [
        ['repository', 'blamed_methods', 'agent_traced', 'traced_pct', 'tools'],
        [$acme->full_name, 2, 1, '50.0', 'cline'],
        [$birch->full_name, 2, 0, '0.0', ''],
    ]);

    $write('run1_trend_repositories.csv', [
        ['metric', 'front_end', 'repository_id', 'n_majors', 'theil_sen_slope', 'mann_kendall_tau', 'mann_kendall_p', 'spearman_rho'],
        ['test_assertion_count', 'all', $acme->id, 4, '0.1250', '0.707', '—', '0.775'],
        ['test_assertion_count', 'all', $birch->id, 6, '0.0000', '0.000', '—', '0.000'],
    ]);

    $write('type_sample_labeled_clean.csv', [
        ['id', 'repository', 'file_path', 'identifier', 'source_excerpt', 'human_label'],
        [$obsAcme->id, $acme->full_name, 'tests/Feature/ExampleTest.php', 'test_example', "public function test_example(): void\n{\n    \$this->assertTrue(true);\n}", 'unit'],
        [$obsBirch->id, $birch->full_name, 'tests/Feature/ExampleTest.php', 'test_example', "it('does the thing')", 'feature'],
    ]);

    $write('type_sample_labeled_clean_confusion.csv', [
        ['human \\ classifier', 'unit', 'feature', 'integration', 'unknown'],
        ['unit', 1, 0, 0, 0],
        ['feature', 0, 1, 0, 0],
        ['integration', 0, 0, 0, 0],
        ['unknown', 0, 0, 0, 0],
    ]);

    $write('run1_descriptives.csv', [
        ['metric', 'n', 'median'],
        ['test_assertion_count', 10, '1.5'],
    ]);

    $write('run1_provenance.csv', [
        ['tool_version', 'ai_cutoff'],
        ['v0.0.0-test', '2022-06-21'],
    ]);

    File::put("{$dir}/corpus.txt", "# fixture corpus\n{$acme->full_name}\n{$birch->full_name}\n");
    File::put("{$dir}/candidates.txt", "{$acme->full_name}\n{$birch->full_name}\n");
}

function runAnonymise(array $extra = [])
{
    /** @var TestCase $test */
    $test = test();

    return $test->artisan('analyse:anonymise', array_merge([
        '--source' => 'storage/framework/testing/anonymise/exports',
        '--out' => 'storage/framework/testing/anonymise/exports-public',
        '--map' => 'storage/framework/testing/anonymise/alias_map.csv',
    ], $extra));
}

beforeEach(function () {
    File::deleteDirectory(anonymiseRoot());
    File::ensureDirectoryExists(anonymiseRoot());
});

afterEach(function () {
    File::deleteDirectory(anonymiseRoot());
});

it('assigns stable aliases in ascending full_name order and re-assigns identically from an existing map', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    runAnonymise()->assertSuccessful();

    $map = anonymiseCsvRows(anonymiseMap());
    expect($map[0])->toBe(['full_name', 'alias', 'repository_id'])
        ->and($map[1][0])->toBe('acme/widgets')->and($map[1][1])->toBe('R01')
        ->and($map[2][0])->toBe('birch/planks')->and($map[2][1])->toBe('R02');

    $before = hash_file('sha256', anonymiseMap());
    runAnonymise()->assertSuccessful();
    expect(hash_file('sha256', anonymiseMap()))->toBe($before);
});

it('extends the map for a new repository without renumbering the existing aliases', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    runAnonymise()->assertSuccessful();

    Candidate::create(['full_name' => 'cedar/timber', 'verdict' => 'excluded']);
    runAnonymise()->assertSuccessful();

    $map = collect(anonymiseCsvRows(anonymiseMap()))->skip(1)->mapWithKeys(
        fn (array $row): array => [$row[0] => $row[1]],
    );
    expect($map['acme/widgets'])->toBe('R01')
        ->and($map['birch/planks'])->toBe('R02')
        ->and($map['cedar/timber'])->toBe('R03');
});

it('resolves shared_history_with to the other repository\'s alias, not to blank', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos(), ['shared' => ['acme/widgets' => 'birch/planks']]);

    runAnonymise()->assertSuccessful();

    $rows = anonymiseCsvRows(anonymiseOut().'/decisionlog.csv');
    $shared = array_search('shared_history_with', $rows[0], true);
    $acmeRow = collect($rows)->skip(1)->first(fn (array $row): bool => $row[0] === 'R01');
    expect($acmeRow[$shared])->toBe('R02');
});

it('drops the SHA columns from the decision log and the three identifying type-sample columns', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    runAnonymise()->assertSuccessful();

    $logHeader = anonymiseCsvRows(anonymiseOut().'/decisionlog.csv')[0];
    expect($logHeader)->not->toContain('root_commit_sha')
        ->and($logHeader)->not->toContain('shared_divergence_sha')
        ->and(anonymiseCsvRows(anonymiseOut().'/type_sample_labeled_clean.csv')[0])
        ->toBe(['id', 'repository', 'human_label', 'classifier_label']);
});

it('fails the gate and deletes the output when a repository name is planted in a passthrough file', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    File::append(anonymiseSource().'/run1_descriptives.csv', "acme/widgets,1,2\n");

    runAnonymise()->expectsOutputToContain('acme/widgets')->assertFailed();

    expect(File::exists(anonymiseOut()))->toBeFalse();
});

it('fails the gate and deletes the output when a commit SHA is planted in a passthrough file', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    File::append(anonymiseSource().'/run1_descriptives.csv', "deadbeef42,1,2\n");

    runAnonymise()->assertFailed();

    expect(File::exists(anonymiseOut()))->toBeFalse();
});

it('does not flag a pure-digit run as a SHA — those are statistics, not abbreviated hashes', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    File::append(anonymiseSource().'/run1_descriptives.csv', "42375569,1,2\n");

    runAnonymise()->assertSuccessful();
});

it('copies files with no identifying data byte-for-byte', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    runAnonymise()->assertSuccessful();

    expect(hash_file('sha256', anonymiseOut().'/run1_descriptives.csv'))
        ->toBe(hash_file('sha256', anonymiseSource().'/run1_descriptives.csv'));
});

it('includes the alias map under --emit-map, warns loudly, and still passes the gate', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    runAnonymise(['--emit-map' => true])
        ->expectsOutputToContain('RE-IDENTIFICATION')
        ->assertSuccessful();

    expect(File::exists(anonymiseOut().'/alias_map.csv'))->toBeTrue();
});

it('fails the run when run1_trend_repositories.csv carries a repository_id missing from the map', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    File::append(anonymiseSource().'/run1_trend_repositories.csv', "test_assertion_count,all,999,4,0.1,0.1,—,0.1\n");

    runAnonymise()->expectsOutputToContain('999')->assertFailed();

    expect(File::exists(anonymiseOut()))->toBeFalse();
});

it('passes corpus.txt through unchanged and lists its exemption in the run summary', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    test()->withoutMockingConsoleOutput();
    $exit = Artisan::call('analyse:anonymise', [
        '--source' => 'storage/framework/testing/anonymise/exports',
        '--out' => 'storage/framework/testing/anonymise/exports-public',
        '--map' => 'storage/framework/testing/anonymise/alias_map.csv',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(hash_file('sha256', anonymiseOut().'/corpus.txt'))
        ->toBe(hash_file('sha256', anonymiseSource().'/corpus.txt'))
        ->and($output)->toContain('named by design');
});

it('still catches a name planted in decisionlog.csv even though corpus.txt legitimately carries it', function () {
    // composer_type passes through untouched, so a name planted there reaches the output
    // and must trip the gate despite the same name sitting, exempt, in corpus.txt.
    makeAnonymiseExportDir(seedAnonymiseRepos(), ['composer_type' => ['birch/planks' => 'acme/widgets']]);

    runAnonymise()->assertFailed();

    expect(File::exists(anonymiseOut()))->toBeFalse();
});

it('exempts a composer_type that collides with a bare repository segment, visibly, without weakening the name rule', function () {
    // LavaLite/cms in the real corpus has composer type `cms` — a manifest package type
    // that equals the repo's own segment. The value is exempted and listed; a full
    // owner/repo planted in the same column still fails (previous test).
    makeAnonymiseExportDir(seedAnonymiseRepos(), ['composer_type' => ['acme/widgets' => 'widgets']]);

    runAnonymise()
        ->expectsOutputToContain('composer_type value(s) widgets')
        ->assertSuccessful();
});

it('redacts free-text cells that match no known reason string and reports the count', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos(), [
        'manual_reason' => ['birch/planks' => 'see my notes at /Users/someone/screening.md'],
    ]);

    runAnonymise()->expectsOutputToContain('1 manual_reason')->assertSuccessful();

    $rows = anonymiseCsvRows(anonymiseOut().'/decisionlog.csv');
    $reason = array_search('manual_reason', $rows[0], true);
    $birchRow = collect($rows)->skip(1)->first(fn (array $row): bool => $row[0] === 'R02');
    expect($birchRow[$reason])->toBe('(redacted free text)');
});

it('rewrites the shared-history reason string to embed the alias rather than redacting it', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos(), [
        'shared' => ['acme/widgets' => 'birch/planks'],
        'manual_reason' => ['acme/widgets' => 'no test suite and shared history with birch/planks'],
    ]);

    runAnonymise()->assertSuccessful();

    $rows = anonymiseCsvRows(anonymiseOut().'/decisionlog.csv');
    $reason = array_search('manual_reason', $rows[0], true);
    $acmeRow = collect($rows)->skip(1)->first(fn (array $row): bool => $row[0] === 'R01');
    expect($acmeRow[$reason])->toBe('no test suite and shared history with R02');
});

it('skips files with no transformation rule and says so, rather than copying them', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    File::put(anonymiseSource().'/pilot_descriptives.csv', "metric,n\nassertions,3\n");

    runAnonymise()->expectsOutputToContain('pilot_descriptives.csv')->assertSuccessful();

    expect(File::exists(anonymiseOut().'/pilot_descriptives.csv'))->toBeFalse();
});

it('fails when the database classifier labels no longer reproduce the published confusion matrix', function () {
    $seeded = seedAnonymiseRepos();
    makeAnonymiseExportDir($seeded);
    $seeded[2]->update(['test_type' => 'integration']);

    runAnonymise()->assertFailed();

    expect(File::exists(anonymiseOut()))->toBeFalse();
});

it('refuses to replace a non-empty --out it did not write, unless --force is passed', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    File::ensureDirectoryExists(anonymiseOut());
    File::put(anonymiseOut().'/precious.txt', 'not ours');

    runAnonymise()->assertFailed();
    expect(File::exists(anonymiseOut().'/precious.txt'))->toBeTrue();

    runAnonymise(['--force' => true])->assertSuccessful();
    expect(File::exists(anonymiseOut().'/precious.txt'))->toBeFalse();
});

it('refuses --prune when the gate failed, leaving the raw exports intact', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    File::append(anonymiseSource().'/run1_descriptives.csv', "acme/widgets,1,2\n");

    runAnonymise(['--prune' => true, '--no-interaction' => true])->assertFailed();

    expect(File::exists(anonymiseSource().'/run1_descriptives.csv'))->toBeTrue()
        ->and(File::exists(anonymiseSource().'/decisionlog.csv'))->toBeTrue();
});

it('refuses when --source and --out resolve to the same directory', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    runAnonymise(['--out' => 'storage/framework/testing/anonymise/exports', '--prune' => true])
        ->expectsOutputToContain('same directory')
        ->assertFailed();

    expect(File::exists(anonymiseSource().'/decisionlog.csv'))->toBeTrue();
});

it('prunes the raw exports after a clean run but never the corpus lists or the map', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    runAnonymise(['--prune' => true, '--no-interaction' => true])
        ->expectsOutputToContain('WORKING TREE only')
        ->assertSuccessful();

    expect(File::exists(anonymiseSource().'/decisionlog.csv'))->toBeFalse()
        ->and(File::exists(anonymiseSource().'/corpus.txt'))->toBeTrue()
        ->and(File::exists(anonymiseSource().'/candidates.txt'))->toBeTrue()
        ->and(File::exists(anonymiseMap()))->toBeTrue();
});

it('hardens the gitignore idempotently — running twice adds each entry once', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    $gitignore = anonymiseRoot().'/scratch.gitignore';
    File::put($gitignore, "/vendor\n");

    $options = ['--harden' => true, '--gitignore' => 'storage/framework/testing/anonymise/scratch.gitignore'];
    runAnonymise($options)->expectsOutputToContain('ignored only while')->assertSuccessful();
    runAnonymise($options)->assertSuccessful();

    $lines = array_count_values(array_filter(file($gitignore, FILE_IGNORE_NEW_LINES)));
    expect($lines['/candidates.txt'])->toBe(1)
        ->and($lines['/corpus.txt'])->toBe(1)
        ->and($lines['/storage/app/alias_map.csv'])->toBe(1)
        ->and($lines['/vendor'])->toBe(1);
});

it('reports a planted name in a tracked file under --scan-repo without failing the run', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());
    $repo = GitFixtureRepo::init(anonymiseRoot().'/scan-repo');
    $repo->write('screening-notes.txt', "reviewed acme/widgets by hand\n");
    $repo->commit('add notes', '2026-01-01T10:00:00Z');

    runAnonymise([
        '--scan-repo' => true,
        '--repo-root' => anonymiseRoot().'/scan-repo',
    ])->expectsOutputToContain('screening-notes.txt')->assertSuccessful();
});

it('writes the appendix corpus table ordered by alias with a pooled total row', function () {
    $seeded = seedAnonymiseRepos();
    [$acme, $birch] = $seeded;
    foreach ([[$acme, 9], [$acme, 10], [$birch, 9]] as [$repository, $major]) {
        $snapshot = Snapshot::create([
            'repository_id' => $repository->id,
            'commit_sha' => "boundary-{$major}",
            'framework_version' => $major,
            'kind' => 'version_boundary',
        ]);
        TestObservation::create([
            'snapshot_id' => $snapshot->id,
            'repository_id' => $repository->id,
            'file_path' => 'tests/Feature/BoundaryTest.php',
            'identifier' => 'test_boundary',
            'front_end' => $repository->is($birch) ? 'pest' : 'phpunit',
            'test_type' => 'feature',
        ]);
    }
    makeAnonymiseExportDir($seeded);

    runAnonymise(['--appendix' => true])->assertSuccessful();

    $rows = anonymiseCsvRows(anonymiseOut().'/appendix_corpus.csv');
    expect($rows[0])->toBe(['alias', 'majors_contributed', 'first_major', 'last_major', 'test_methods', 'paradigm_at_head', 'agent_trace_pct'])
        ->and($rows[1])->toBe(['R01', '2', '9', '10', '2', 'phpunit', '50.0'])
        ->and($rows[2])->toBe(['R02', '1', '9', '9', '2', 'pest', '0.0'])
        // The total rate is pooled — 1 traced of 4 blamed — not the mean of 50.0 and 0.0.
        ->and($rows[3])->toBe(['(all)', '3', '9', '10', '4', '—', '25.0'])
        ->and(File::exists(anonymiseOut().'/appendix_corpus.md'))->toBeTrue();
});

it('generates a README that carries the provenance, the withheld-map statement, and passes the gate', function () {
    makeAnonymiseExportDir(seedAnonymiseRepos());

    runAnonymise(['--readme' => true])->assertSuccessful();

    $readme = File::get(anonymiseOut().'/README.md');
    expect($readme)->toContain('v0.0.0-test')
        ->and($readme)->toContain('not** included')
        ->and($readme)->toContain('source_excerpt');
});
