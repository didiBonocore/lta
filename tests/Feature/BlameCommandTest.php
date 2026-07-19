<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Tests\Support\GitFixtureRepo;

/**
 * Instrument B on a deterministic history: one test authored before the default cutoff
 * (2022-11-30), one PHPUnit method and one Pest test authored after it — including a method
 * appended to a pre-existing file, the case a whole-file date would get wrong.
 */
beforeEach(function () {
    $this->repo = GitFixtureRepo::init(base_path('storage/framework/testing/blame-repo'));

    $this->repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^9.0']]));
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

it('defaults the cutoff to the supervisor-pending date and honours the env override', function () {
    expect(config('analyser.ai_cutoff'))->toBe('2022-11-30');
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

it('blames only the newest extracted snapshot, leaving older snapshots null', function () {
    // Build Instrument A rows too: the version-boundary snapshot sits at the boot commit.
    $this->artisan('analyse:snapshot', ['full_name' => 'acme/eras'])->assertSuccessful();
    $this->artisan('analyse:extract', ['full_name' => 'acme/eras'])->assertSuccessful();

    $this->artisan('analyse:blame', ['full_name' => 'acme/eras'])->assertSuccessful();

    $head = Snapshot::where('kind', 'head')->sole();
    $boundary = Snapshot::where('kind', 'version_boundary')->sole();

    expect($head->observations()->whereNotNull('ai_window')->count())->toBe(3)
        ->and($boundary->observations()->count())->toBe(1)
        ->and($boundary->observations()->whereNull('ai_window')->count())->toBe(1);
});

it('counts an observation without a line range as unattributable and leaves its columns null', function () {
    $orphan = TestObservation::where('identifier', 'test_legacy')->sole()->replicate();
    $orphan->identifier = 'test_unattributable';
    $orphan->start_line = null;
    $orphan->end_line = null;
    $orphan->save();

    $this->artisan('analyse:blame', ['full_name' => 'acme/eras'])->assertSuccessful();

    $row = TestObservation::where('identifier', 'test_unattributable')->sole();
    expect($row->introduced_commit_sha)->toBeNull()
        ->and($row->introduced_author_date)->toBeNull()
        ->and($row->ai_window)->toBeNull()
        ->and(TestObservation::whereNotNull('ai_window')->count())->toBe(3);
});

it('fails when the repository has not been acquired', function () {
    $this->artisan('analyse:blame', ['full_name' => 'nobody/nothing'])->assertFailed();
});
