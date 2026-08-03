<?php

declare(strict_types=1);

use App\Models\ParseFailure;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use App\Models\UnroutableFile;
use Tests\Support\GitFixtureRepo;

/**
 * Builds a real (local, throwaway) git history: a Laravel 9 boot commit, a test-only commit
 * (does not touch composer.json), a bump to 10, then another test-only commit. The
 * representative per major is the LAST composer.json-touching commit still resolving to that
 * major: commit 1 for Laravel 9, the bump commit for Laravel 10 — never HEAD.
 */
beforeEach(function () {
    $this->repo = GitFixtureRepo::init(base_path('storage/framework/testing/snapshot-repo'));

    $this->repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^9.0']]));
    $this->repo->write('tests/Unit/AlphaTest.php', GitFixtureRepo::phpUnitTestClass('AlphaTest', 'test_alpha'));
    $this->commitOnNine = $this->repo->commit('boot on laravel 9', '2022-01-10T10:00:00Z');

    $this->repo->write('tests/Unit/BetaTest.php', GitFixtureRepo::phpUnitTestClass('BetaTest', 'test_beta'));
    $this->matureNine = $this->repo->commit('add beta test', '2022-09-01T10:00:00Z');

    $this->repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^10.0']]));
    $this->bumpToTen = $this->repo->commit('upgrade to laravel 10', '2023-02-20T10:00:00Z');

    $this->repo->write('tests/Unit/GammaTest.php', GitFixtureRepo::phpUnitTestClass('GammaTest', 'test_gamma'));
    $this->headSha = $this->repo->commit('add gamma test', '2023-06-15T10:00:00Z');

    $this->repository = Repository::create([
        'full_name' => 'acme/history',
        'owner' => 'acme',
        'name' => 'history',
        'url' => 'https://github.com/acme/history.git',
        'clone_path' => $this->repo->root,
        'head_sha' => $this->headSha,
    ]);
});

afterEach(function () {
    $this->repo->destroy();
});

it('snapshots each integer major at its last constraint commit for that major', function () {
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/history'])->assertSuccessful();

    expect(Snapshot::count())->toBe(2);

    $nine = Snapshot::where('framework_version', 9)->sole();
    expect($nine->commit_sha)->toBe($this->commitOnNine)
        ->and($nine->kind)->toBe('version_boundary')
        ->and($nine->commit_date->toDateString())->toBe('2022-01-10');

    $ten = Snapshot::where('framework_version', 10)->sole();
    expect($ten->commit_sha)->toBe($this->bumpToTen)
        ->and($ten->commit_date->toDateString())->toBe('2023-02-20');
});

it('is idempotent: re-snapshotting updates rather than duplicates', function () {
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/history'])->assertSuccessful();
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/history'])->assertSuccessful();

    expect(Snapshot::count())->toBe(2);
});

it('extracts every version-boundary snapshot through git show without touching the working tree', function () {
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/history'])->assertSuccessful();
    $this->artisan('analyse:extract', ['full_name' => 'acme/history'])->assertSuccessful();

    $nine = Snapshot::where('framework_version', 9)->sole();
    $ten = Snapshot::where('framework_version', 10)->sole();

    // Laravel 9's representative is the boot commit (only Alpha existed); the bump commit's
    // tree carries Alpha + Beta; Gamma (added after the bump) is in no snapshot.
    expect($nine->observations()->pluck('file_path')->all())->toBe([
        'tests/Unit/AlphaTest.php',
    ])->and($ten->observations()->pluck('file_path')->sort()->values()->all())->toBe([
        'tests/Unit/AlphaTest.php',
        'tests/Unit/BetaTest.php',
    ])->and(TestObservation::count())->toBe(3);

    // The clone's checkout was never moved off HEAD:
    expect($this->repo->head())->toBe($this->headSha);
});

it('records each representative\'s position in the first-parent walk', function () {
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/history'])->assertSuccessful();

    // Touches on the first-parent line: the boot commit (index 0), the bump (index 1).
    expect(Snapshot::where('framework_version', 9)->sole()->first_parent_index)->toBe(0)
        ->and(Snapshot::where('framework_version', 10)->sole()->first_parent_index)->toBe(1);
});

it('selects the merge commit, not the branch commit, when a bump lands via a merge', function () {
    $repo = GitFixtureRepo::init(base_path('storage/framework/testing/merge-repo'));

    $repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^9.0']]));
    $repo->write('tests/Unit/AlphaTest.php', GitFixtureRepo::phpUnitTestClass('AlphaTest', 'test_alpha'));
    $boot = $repo->commit('boot on laravel 9', '2022-01-10T10:00:00Z');

    // The bump is authored on a branch, deliberately dated BEFORE trunk moves on — the
    // shape that produced the pilot's non-monotonic representative dates.
    $repo->branch('upgrade');
    $repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^10.0']]));
    $branchBump = $repo->commit('bump to laravel 10 on a branch', '2022-06-01T10:00:00Z');

    $repo->checkout('main');
    $repo->write('tests/Unit/BetaTest.php', GitFixtureRepo::phpUnitTestClass('BetaTest', 'test_beta'));
    $repo->commit('trunk moves on', '2023-01-05T10:00:00Z');
    $merge = $repo->merge('upgrade', 'merge the laravel 10 upgrade', '2023-02-20T10:00:00Z');

    Repository::create([
        'full_name' => 'acme/merger',
        'owner' => 'acme',
        'name' => 'merger',
        'url' => 'https://github.com/acme/merger.git',
        'clone_path' => $repo->root,
        'head_sha' => $merge,
    ]);

    $this->artisan('analyse:snapshot', ['full_name' => 'acme/merger'])->assertSuccessful();

    // Laravel 10's representative is the merge commit — the date the constraint LANDED on
    // trunk — never the branch commit, whose author date carries no trunk ordering.
    $ten = Snapshot::where('framework_version', 10)->sole();
    expect($ten->commit_sha)->toBe($merge)
        ->and($ten->commit_sha)->not->toBe($branchBump)
        ->and($ten->commit_date->toDateString())->toBe('2023-02-20');

    $nine = Snapshot::where('framework_version', 9)->sole();
    expect($nine->commit_sha)->toBe($boot)
        ->and($nine->first_parent_index)->toBeLessThan($ten->first_parent_index);

    $repo->destroy();
});

it('prunes majors whose constraint only ever existed off-trunk, with their dependents', function () {
    $repo = GitFixtureRepo::init(base_path('storage/framework/testing/prune-repo'));

    $repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^9.0']]));
    $repo->commit('boot on laravel 9', '2022-01-10T10:00:00Z');

    // The branch passes through ^10 before landing at ^11, so 10 exists ONLY off-trunk.
    $repo->branch('upgrade');
    $repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^10.0']]));
    $transient = $repo->commit('bump to 10 mid-branch', '2023-01-01T10:00:00Z');
    $repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^11.0']]));
    $repo->commit('continue to 11 on the branch', '2023-06-01T10:00:00Z');

    $repo->checkout('main');
    $merge = $repo->merge('upgrade', 'merge the upgrade', '2024-03-01T10:00:00Z');

    $repository = Repository::create([
        'full_name' => 'acme/pruned',
        'owner' => 'acme',
        'name' => 'pruned',
        'url' => 'https://github.com/acme/pruned.git',
        'clone_path' => $repo->root,
        'head_sha' => $merge,
    ]);

    // Simulate the pre-change state: a snapshot for the off-trunk major 10 with dependent
    // rows, as an all-commits walk would have produced.
    $stale = Snapshot::create([
        'repository_id' => $repository->id,
        'commit_sha' => $transient,
        'framework_version' => 10,
        'kind' => 'version_boundary',
    ]);
    TestObservation::create([
        'snapshot_id' => $stale->id,
        'repository_id' => $repository->id,
        'file_path' => 'tests/StaleTest.php',
        'identifier' => 'test_stale',
        'front_end' => 'phpunit',
        'test_type' => 'unit',
    ]);
    ParseFailure::create([
        'snapshot_id' => $stale->id,
        'repository_id' => $repository->id,
        'file_path' => 'tests/BrokenTest.php',
        'commit_sha' => $transient,
        'message' => 'stale',
    ]);
    UnroutableFile::create([
        'snapshot_id' => $stale->id,
        'repository_id' => $repository->id,
        'file_path' => 'tests/OtherFramework.php',
        'commit_sha' => $transient,
        'base_class' => 'Codeception\TestCase\Test',
    ]);

    $this->artisan('analyse:snapshot', ['full_name' => 'acme/pruned'])->assertSuccessful();

    // Trunk only ever held 9 and 11; the off-trunk 10 is gone along with every dependent,
    // so analyse:verify's orphan check passes without a manual reset.
    expect(Snapshot::where('repository_id', $repository->id)->pluck('framework_version')->sort()->values()->all())->toBe([9, 11])
        ->and(TestObservation::where('repository_id', $repository->id)->count())->toBe(0)
        ->and(ParseFailure::where('repository_id', $repository->id)->count())->toBe(0)
        ->and(UnroutableFile::where('repository_id', $repository->id)->count())->toBe(0);

    $repo->destroy();
});

it('fails when no composer.json commit resolves to a Laravel major', function () {
    $plain = GitFixtureRepo::init(base_path('storage/framework/testing/no-laravel-repo'));
    $plain->write('composer.json', json_encode(['require' => ['symfony/console' => '^6.0']]));
    $plain->commit('not a laravel app', '2022-01-01T10:00:00Z');

    Repository::create([
        'full_name' => 'acme/plain',
        'owner' => 'acme',
        'name' => 'plain',
        'url' => 'https://github.com/acme/plain.git',
        'clone_path' => $plain->root,
        'head_sha' => 'irrelevant',
    ]);

    $this->artisan('analyse:snapshot', ['full_name' => 'acme/plain'])->assertFailed();
    expect(Snapshot::count())->toBe(0);

    $plain->destroy();
});
