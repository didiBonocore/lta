<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\TestObservation;
use Tests\Support\GitFixtureRepo;

/**
 * Instrument B on a deterministic history: one test authored before the AI cutoff
 * (2022-06-21, Copilot GA), one PHPUnit method and one Pest test authored after it —
 * including a method appended to a pre-existing file, the case a file-add date would get wrong.
 */
beforeEach(function () {
    $this->repo = GitFixtureRepo::init(base_path('storage/framework/testing/blame-repo'));

    $this->repo->write('tests/Unit/LegacyTest.php', GitFixtureRepo::phpUnitTestClass('LegacyTest', 'test_legacy'));
    $this->preCommit = $this->repo->commit('legacy test, pre-AI era', '2021-05-01T10:00:00Z');

    $this->repo->write('tests/Unit/LegacyTest.php', GitFixtureRepo::phpUnitTestClass('LegacyTest', 'test_legacy', 'test_modern'));
    $this->repo->write('tests/Unit/ModernPestTest.php', <<<'PHP'
        <?php

        it('charges in the modern era', function () {
            expect(true)->toBeTrue();
        });
        PHP);
    $this->postCommit = $this->repo->commit('modern tests, post-AI era', '2023-03-01T10:00:00Z');

    $this->repository = Repository::create([
        'full_name' => 'acme/eras',
        'owner' => 'acme',
        'name' => 'eras',
        'url' => 'https://github.com/acme/eras.git',
        'clone_path' => $this->repo->root,
        'head_sha' => $this->repo->head(),
    ]);

    $this->artisan('analyse:extract', ['full_name' => 'acme/eras', '--head' => true])->assertSuccessful();
});

afterEach(function () {
    $this->repo->destroy();
});

it('keeps the frozen cutoff dates in config', function () {
    expect(config('analyser.ai_cutoff'))->toBe('2022-06-21')
        ->and(config('analyser.ai_cutoff_sensitivity'))->toBe('2022-11-30');
});

it('attributes each test method to its introducing commit and buckets the AI window', function () {
    $this->artisan('analyse:blame', ['full_name' => 'acme/eras'])->assertSuccessful();

    $legacy = TestObservation::where('identifier', 'test_legacy')->sole();
    expect($legacy->introduced_commit_sha)->toBe($this->preCommit)
        ->and($legacy->introduced_author_date->toDateString())->toBe('2021-05-01')
        ->and($legacy->ai_window)->toBe('pre');

    // Appended to a file that predates the cutoff — must date to its own commit, not the file's.
    $modern = TestObservation::where('identifier', 'test_modern')->sole();
    expect($modern->introduced_commit_sha)->toBe($this->postCommit)
        ->and($modern->introduced_author_date->toDateString())->toBe('2023-03-01')
        ->and($modern->ai_window)->toBe('post');

    $pest = TestObservation::where('identifier', 'charges in the modern era')->sole();
    expect($pest->introduced_commit_sha)->toBe($this->postCommit)
        ->and($pest->ai_window)->toBe('post');
});

it('re-buckets against an overridden cutoff for sensitivity runs', function () {
    $this->artisan('analyse:blame', ['full_name' => 'acme/eras', '--cutoff' => '2031-01-01'])
        ->assertSuccessful();

    expect(TestObservation::where('ai_window', 'pre')->count())->toBe(3)
        ->and(TestObservation::where('ai_window', 'post')->count())->toBe(0);
});

it('fails when the repository has not been acquired', function () {
    $this->artisan('analyse:blame', ['full_name' => 'nobody/nothing'])->assertFailed();
});
