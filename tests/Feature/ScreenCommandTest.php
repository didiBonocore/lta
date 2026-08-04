<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\Repository;
use App\Models\Snapshot;
use Illuminate\Support\Facades\File;
use Tests\Support\GitFixtureRepo;

/**
 * The decision log as a byproduct of executing Appendix A. Phase 1 runs against a real
 * throwaway git history; phase 2 (pool quartiles) is exercised over seeded candidates
 * because it is pure arithmetic over the log.
 */
function screenFixtureRepo(string $name = 'acme/screened', string $dir = 'screen-repo'): GitFixtureRepo
{
    $repo = GitFixtureRepo::init(base_path("storage/framework/testing/{$dir}"));

    $repo->write('composer.json', json_encode(['type' => 'project', 'require' => ['laravel/framework' => '^9.0']]));
    $repo->write('app/Service.php', "<?php\n\nclass Service\n{\n}\n");
    $repo->write('tests/Unit/AlphaTest.php', GitFixtureRepo::phpUnitTestClass('AlphaTest', 'test_alpha'));
    // Excluded from the proportion denominator: templates and translation data, not code.
    $repo->write('resources/views/welcome.blade.php', "<?php echo 'template'; ?>\n");
    $repo->write('lang/en/messages.php', "<?php\n\nreturn ['hello' => 'Hello'];\n");
    $repo->write('resources/lang/de/messages.php', "<?php\n\nreturn ['hello' => 'Hallo'];\n");
    $repo->commit('boot on laravel 9', '2022-01-10T10:00:00Z');

    $repo->write('composer.json', json_encode(['type' => 'project', 'require' => ['laravel/framework' => '^10.0']]));
    $repo->commit('upgrade to laravel 10', '2023-02-20T10:00:00Z');

    $repo->write('composer.json', json_encode(['type' => 'project', 'require' => ['laravel/framework' => '^11.0']]));
    $repo->commit('upgrade to laravel 11', '2024-03-05T10:00:00Z');

    Repository::create([
        'full_name' => $name,
        'owner' => explode('/', $name)[0],
        'name' => explode('/', $name)[1],
        'url' => "https://github.com/{$name}.git",
        'clone_path' => $repo->root,
        'head_sha' => $repo->head(),
        'is_fork' => false,
    ]);

    return $repo;
}

/** Seed a phase-1-passing candidate directly — phase 2 is arithmetic over the log. */
function seedCandidate(string $fullName, float $fileProportion, float $locProportion, ?string $manual = null): Candidate
{
    return Candidate::create([
        'full_name' => $fullName,
        'dependency_ok' => 'pass',
        'majors_ok' => 'pass',
        'suite_ok' => 'pass',
        'cloneable_ok' => 'pass',
        'package_ok' => 'pass',
        'fork_ok' => 'pass',
        'test_file_proportion' => $fileProportion,
        'test_loc_proportion' => $locProportion,
        'manual_decision' => $manual,
        'manual_ok' => $manual === null ? 'pending' : ($manual === 'include' ? 'pass' : 'fail'),
        'screened_at' => now(),
    ]);
}

it('screens an acquired candidate, recording every criterion with its measured value', function () {
    $repo = screenFixtureRepo();

    $this->artisan('analyse:screen', ['full_name' => 'acme/screened'])->assertSuccessful();

    $candidate = Candidate::sole();
    expect($candidate->full_name)->toBe('acme/screened')
        ->and($candidate->dependency_ok)->toBe('pass')
        ->and($candidate->framework_constraint)->toBe('^11.0')
        ->and($candidate->majors_ok)->toBe('pass')
        ->and($candidate->majors_count)->toBe(3)
        ->and($candidate->suite_ok)->toBe('pass')
        ->and($candidate->suite_routed_files)->toBe(1)
        ->and($candidate->cloneable_ok)->toBe('pass')
        ->and($candidate->package_ok)->toBe('pass')
        ->and($candidate->composer_type)->toBe('project')
        ->and($candidate->fork_ok)->toBe('pass')
        // welcome.blade.php and both lang/ files are excluded: 2 counted, not 5.
        ->and($candidate->test_file_count)->toBe(1)
        ->and($candidate->php_file_count)->toBe(2)
        ->and($candidate->test_file_proportion)->toBe(0.5)
        ->and($candidate->test_loc_proportion)->toBeGreaterThan(0.0)
        // Pool-relative criteria stay pending until --finalise; so does the verdict.
        ->and($candidate->file_proportion_ok)->toBe('pending')
        ->and($candidate->loc_proportion_ok)->toBe('pending')
        ->and($candidate->manual_ok)->toBe('pending')
        ->and($candidate->verdict)->toBe('pending')
        ->and($candidate->root_commit_sha)->not->toBeNull();

    $repo->destroy();
});

it('counts majors by the same rule analyse:snapshot mines by', function () {
    $repo = screenFixtureRepo();

    $this->artisan('analyse:screen', ['full_name' => 'acme/screened'])->assertSuccessful();
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/screened'])->assertSuccessful();

    // A divergence here means the corpus is defined by one rule and measured by another.
    expect(Candidate::sole()->majors_count)
        ->toBe(Snapshot::where('kind', 'version_boundary')->count());

    $repo->destroy();
});

it('re-screens idempotently, preserving the manual judgement', function () {
    $repo = screenFixtureRepo();

    $this->artisan('analyse:screen', ['full_name' => 'acme/screened'])->assertSuccessful();
    $this->artisan('analyse:screen', [
        'full_name' => 'acme/screened', '--manual' => 'exclude', '--reason' => 'course project',
    ])->assertSuccessful();
    $this->artisan('analyse:screen', ['full_name' => 'acme/screened'])->assertSuccessful();

    expect(Candidate::count())->toBe(1)
        ->and(Candidate::sole()->manual_decision)->toBe('exclude')
        ->and(Candidate::sole()->manual_reason)->toBe('course project')
        ->and(Candidate::sole()->manual_ok)->toBe('fail');

    $repo->destroy();
});

it('rejects a manual judgement without a reason or without a screened row', function () {
    $this->artisan('analyse:screen', ['full_name' => 'acme/nowhere', '--manual' => 'exclude', '--reason' => 'x'])
        ->assertFailed();

    $repo = screenFixtureRepo();
    $this->artisan('analyse:screen', ['full_name' => 'acme/screened'])->assertSuccessful();
    $this->artisan('analyse:screen', ['full_name' => 'acme/screened', '--manual' => 'exclude'])
        ->assertFailed();
    $this->artisan('analyse:screen', ['full_name' => 'acme/screened', '--manual' => 'maybe', '--reason' => 'x'])
        ->assertFailed();

    $repo->destroy();
});

it('fails to screen a candidate that was never acquired', function () {
    $this->artisan('analyse:screen', ['full_name' => 'acme/unacquired'])->assertFailed();
    expect(Candidate::count())->toBe(0);
});

it('refuses to finalise a degenerate pool and says why', function () {
    seedCandidate('acme/a', 0.10, 0.10);
    seedCandidate('acme/b', 0.20, 0.20);

    $this->artisan('analyse:screen', ['--finalise' => true])
        ->expectsOutputToContain('Refusing to finalise: 2 candidate(s) pass phase 1')
        ->assertFailed();

    expect(Candidate::where('verdict', '!=', 'pending')->count())->toBe(0);
});

it('finalises a sufficient pool: quartile applied, manual gate never defaulting to included', function () {
    // Proportions 0.10 … 0.80: Q1 (type 7) = 0.275, so 0.10 and 0.20 fall below it.
    foreach (range(1, 8) as $i) {
        seedCandidate("acme/c{$i}", $i / 10, $i / 10, $i >= 2 ? 'include' : null);
    }
    $corpus = 'storage/framework/testing/corpus-out.txt';

    $this->artisan('analyse:screen', ['--finalise' => true, '--corpus' => $corpus])
        ->expectsOutputToContain('First quartiles')
        ->assertSuccessful();

    // Below-quartile candidates are excluded even with manual=include; above-quartile ones
    // are included only when the manual criterion is answered — c1 has everything except
    // the manual answer and must stay pending... but c1 is also below the quartile, so
    // assert the pending path on a fresh above-quartile candidate instead.
    expect(Candidate::where('full_name', 'acme/c1')->sole()->verdict)->toBe('excluded')
        ->and(Candidate::where('full_name', 'acme/c2')->sole()->verdict)->toBe('excluded')
        ->and(Candidate::where('full_name', 'acme/c3')->sole()->verdict)->toBe('included')
        ->and(Candidate::where('full_name', 'acme/c8')->sole()->verdict)->toBe('included');

    $written = file(base_path($corpus), FILE_IGNORE_NEW_LINES) ?: [];
    $names = array_values(array_filter($written, fn (string $l): bool => ! str_starts_with($l, '#')));
    expect($names)->toBe(['acme/c3', 'acme/c4', 'acme/c5', 'acme/c6', 'acme/c7', 'acme/c8']);

    File::delete(base_path($corpus));
});

it('leaves an above-quartile candidate pending until its manual criterion is answered', function () {
    foreach (range(1, 8) as $i) {
        seedCandidate("acme/c{$i}", $i / 10, $i / 10, $i === 5 ? null : 'include');
    }

    $this->artisan('analyse:screen', ['--finalise' => true, '--corpus' => 'storage/framework/testing/corpus-out.txt'])
        ->assertSuccessful();

    expect(Candidate::where('full_name', 'acme/c5')->sole()->verdict)->toBe('pending');

    File::delete(base_path('storage/framework/testing/corpus-out.txt'));
});

it('revises earlier verdicts when the pool changes and re-finalising moves the quartile', function () {
    $corpus = 'storage/framework/testing/corpus-out.txt';
    foreach (range(1, 8) as $i) {
        seedCandidate("acme/c{$i}", $i / 10, $i / 10, 'include');
    }
    $this->artisan('analyse:screen', ['--finalise' => true, '--corpus' => $corpus])->assertSuccessful();
    expect(Candidate::where('full_name', 'acme/c3')->sole()->verdict)->toBe('included'); // 0.30 vs Q1 0.275

    // Four high-proportion candidates raise Q1 from 0.275 to 0.375: c3 flips to excluded.
    foreach (range(9, 12) as $i) {
        seedCandidate("acme/c{$i}", 0.9, 0.9, 'include');
    }
    $this->artisan('analyse:screen', ['--finalise' => true, '--corpus' => $corpus])->assertSuccessful();

    expect(Candidate::where('full_name', 'acme/c3')->sole()->verdict)->toBe('excluded')
        ->and(Candidate::where('full_name', 'acme/c4')->sole()->verdict)->toBe('included');

    File::delete(base_path($corpus));
});

it('exports the decision log as CSV readable without the database', function () {
    seedCandidate('acme/a', 0.10, 0.15, 'exclude');
    seedCandidate('acme/b', 0.20, 0.25);
    $path = base_path('storage/framework/testing/decision-log.csv');

    $this->artisan('analyse:screen', ['--export' => $path])->assertSuccessful();

    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), file($path, FILE_IGNORE_NEW_LINES) ?: []);
    expect($rows)->toHaveCount(3)
        ->and($rows[0][0])->toBe('full_name')
        ->and($rows[1][0])->toBe('acme/a')
        ->and($rows[2][0])->toBe('acme/b');

    $manualReason = array_search('manual_reason', $rows[0], true);
    expect($rows[0])->toContain('test_file_proportion')
        ->and($manualReason)->not->toBeFalse();

    File::delete($path);
});

it('flags both halves of a shared-history pair for the manual gate', function () {
    $upstream = screenFixtureRepo('acme/upstream', 'shared-upstream');

    // The hard fork: identical early history (same commit SHAs), independent development after.
    $forkRoot = base_path('storage/framework/testing/shared-fork');
    File::deleteDirectory($forkRoot);
    File::copyDirectory($upstream->root, $forkRoot);
    $fork = new GitFixtureRepo($forkRoot);
    $fork->write('tests/Unit/ForkOnlyTest.php', GitFixtureRepo::phpUnitTestClass('ForkOnlyTest', 'test_fork'));
    $fork->commit('independent development on the fork', '2025-01-01T10:00:00Z');

    Repository::create([
        'full_name' => 'acme/fork',
        'owner' => 'acme',
        'name' => 'fork',
        'url' => 'https://github.com/acme/fork.git',
        'clone_path' => $forkRoot,
        'head_sha' => $fork->head(),
        'is_fork' => false, // GitHub does not know it is a hard fork — that is the point
    ]);

    $this->artisan('analyse:screen', ['full_name' => 'acme/upstream'])->assertSuccessful();
    $this->artisan('analyse:screen', ['full_name' => 'acme/fork'])
        ->expectsOutputToContain('Shares history with acme/upstream')
        ->assertSuccessful();

    $upstreamRow = Candidate::where('full_name', 'acme/upstream')->sole();
    $forkRow = Candidate::where('full_name', 'acme/fork')->sole();

    expect($forkRow->shared_history_with)->toBe('acme/upstream')
        ->and($upstreamRow->shared_history_with)->toBe('acme/fork')
        ->and($forkRow->shared_divergence_sha)->toBe($upstream->head())
        ->and($upstreamRow->shared_divergence_sha)->toBe($upstream->head());

    $upstream->destroy();
    File::deleteDirectory($forkRoot);
});
