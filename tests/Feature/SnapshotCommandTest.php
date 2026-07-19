<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
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
