<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\TestObservation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\GitFixtureRepo;

/**
 * Drives the real pipeline over a corpus file. The healthy repository is a synthetic git
 * repo pre-placed at analyse:acquire's default destination, so acquire skips the network
 * clone and every later stage runs for real. The broken repository's clone is faked to
 * fail, proving continue-on-failure and the end-of-batch failure listing.
 */
beforeEach(function () {
    $this->repo = GitFixtureRepo::init(base_path('storage/corpus/acme__eras'));
    $this->repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^9.0']]));
    $this->repo->write('tests/Unit/LegacyTest.php', GitFixtureRepo::phpUnitTestClass('LegacyTest', 'test_legacy'));
    $this->repo->commit('legacy test, pre-AI era', '2021-05-01T10:00:00Z');
    $this->repo->write('composer.json', json_encode(['require' => ['laravel/framework' => '^10.0']]));
    $this->repo->write('tests/Unit/ModernTest.php', GitFixtureRepo::phpUnitTestClass('ModernTest', 'test_modern'));
    $this->repo->commit('modern test on laravel 10, post-AI era', '2023-03-01T10:00:00Z');

    $this->corpus = base_path('storage/framework/testing/corpus.txt');
    File::ensureDirectoryExists(dirname($this->corpus));

    Http::fake(['api.github.com/*' => Http::response(['license' => ['spdx_id' => 'MIT'], 'created_at' => '2020-01-01T00:00:00Z'])]);
});

afterEach(function () {
    $this->repo->destroy();
    File::delete($this->corpus);
});

it('runs the full pipeline per repository and summarises the batch', function () {
    File::put($this->corpus, "# pilot\n\nacme/eras\n");

    $this->artisan('analyse:batch', ['file' => $this->corpus])
        ->expectsOutputToContain('All 1 repositories completed.')
        ->assertSuccessful();

    // Major 9's snapshot carries the legacy test; major 10's carries both; blame covers
    // the newest snapshot's two methods.
    expect(Repository::where('full_name', 'acme/eras')->exists())->toBeTrue()
        ->and(TestObservation::count())->toBe(3)
        ->and(TestObservation::whereNotNull('ai_window')->count())->toBe(2);
});

it('continues past a failing repository and lists it at the end', function () {
    File::put($this->corpus, "acme/missing\nacme/eras\n");

    // Only acme/missing needs a clone (acme/eras is already on disk); make it fail.
    Process::fake(['*clone*' => Process::result('', 'remote: Repository not found.', 128)]);

    $this->artisan('analyse:batch', ['file' => $this->corpus])
        ->expectsOutputToContain('1 of 2 repositories failed: acme/missing')
        ->assertFailed();

    // The healthy repository still went all the way through.
    expect(TestObservation::count())->toBe(3)
        ->and(TestObservation::whereNotNull('ai_window')->count())->toBe(2)
        ->and(Repository::where('full_name', 'acme/missing')->exists())->toBeFalse();
});

it('fails on a missing or empty corpus file', function () {
    $this->artisan('analyse:batch', ['file' => 'nope.txt'])->assertFailed();

    File::put($this->corpus, "# only comments\n");
    $this->artisan('analyse:batch', ['file' => $this->corpus])->assertFailed();
});
