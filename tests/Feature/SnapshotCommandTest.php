<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Tests\Support\GitFixtureRepo;

/**
 * Builds a real (local, throwaway) git history: two commits on Laravel 9, a bump to 10,
 * then one more commit. Instrument A must pick the parent of the bump commit as Laravel 9's
 * representative (the mature 9 state) and HEAD for the still-current major 10.
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

it('snapshots each integer major at its mature pre-bump state, and HEAD for the current major', function () {
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/history'])->assertSuccessful();

    expect(Snapshot::count())->toBe(2);

    $nine = Snapshot::where('framework_version', 9)->sole();
    expect($nine->commit_sha)->toBe($this->matureNine)
        ->and($nine->kind)->toBe('version_boundary')
        ->and($nine->commit_date->toDateString())->toBe('2022-09-01');

    $ten = Snapshot::where('framework_version', 10)->sole();
    expect($ten->commit_sha)->toBe($this->headSha)
        ->and($ten->commit_date->toDateString())->toBe('2023-06-15');
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

    expect($nine->observations()->pluck('file_path')->sort()->values()->all())->toBe([
        'tests/Unit/AlphaTest.php',
        'tests/Unit/BetaTest.php',
    ])->and($ten->observations()->count())->toBe(3)
        ->and(TestObservation::count())->toBe(5);

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
