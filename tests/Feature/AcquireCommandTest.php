<?php

declare(strict_types=1);

use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('full-clones a new repository and records provenance with GitHub metadata', function () {
    Process::preventStrayProcesses();
    Process::fake([
        '*clone*' => Process::result(),
        '*rev-parse*' => Process::result("abc123def456\n"),
    ]);
    Http::fake([
        'api.github.com/repos/acme/shop' => Http::response([
            'license' => ['spdx_id' => 'MIT'],
            'created_at' => '2019-03-04T12:00:00Z',
        ]),
    ]);

    $this->artisan('analyse:acquire', ['full_name' => 'acme/shop'])->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === ['git', 'clone', 'https://github.com/acme/shop.git', base_path('storage/corpus/acme__shop')]);

    $repository = Repository::sole();
    expect($repository->full_name)->toBe('acme/shop')
        ->and($repository->owner)->toBe('acme')
        ->and($repository->name)->toBe('shop')
        ->and($repository->head_sha)->toBe('abc123def456')
        ->and($repository->license)->toBe('MIT')
        ->and($repository->github_created_at->toDateString())->toBe('2019-03-04')
        ->and($repository->cloned_at)->not->toBeNull();
});

it('skips the clone when the repository is already on disk', function () {
    $dest = base_path('storage/framework/testing/corpus/acme__shop');
    File::ensureDirectoryExists($dest);

    Process::preventStrayProcesses();
    Process::fake(['*rev-parse*' => Process::result("feedbeef\n")]);
    Http::fake(['api.github.com/*' => Http::response(['license' => null, 'created_at' => null])]);

    $this->artisan('analyse:acquire', [
        'full_name' => 'acme/shop',
        '--path' => 'storage/framework/testing/corpus',
    ])->assertSuccessful();

    Process::assertNotRan(fn ($process) => in_array('clone', (array) $process->command, true));
    expect(Repository::sole()->head_sha)->toBe('feedbeef');

    File::deleteDirectory(base_path('storage/framework/testing/corpus'));
});

it('still acquires when the GitHub API is unavailable', function () {
    Process::preventStrayProcesses();
    Process::fake([
        '*clone*' => Process::result(),
        '*rev-parse*' => Process::result("abc123\n"),
    ]);
    Http::fake(['api.github.com/*' => Http::response(null, 403)]);

    $this->artisan('analyse:acquire', ['full_name' => 'acme/shop'])->assertSuccessful();

    $repository = Repository::sole();
    expect($repository->license)->toBeNull()
        ->and($repository->github_created_at)->toBeNull()
        ->and($repository->head_sha)->toBe('abc123');
});

it('rejects a name that is not owner/repo', function () {
    $this->artisan('analyse:acquire', ['full_name' => 'not-a-full-name'])->assertFailed();

    expect(Repository::count())->toBe(0);
});
