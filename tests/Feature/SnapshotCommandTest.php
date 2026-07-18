<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Builds a real (local, throwaway) git history: two commits on Laravel 9, a bump to 10,
 * then one more commit. Instrument A must pick the parent of the bump commit as Laravel 9's
 * representative (the mature 9 state) and HEAD for the still-current major 10.
 */
function gitInRepo(string $root, array $arguments, array $env = []): string
{
    $process = new Process(['git', ...$arguments], $root, [
        'GIT_AUTHOR_NAME' => 'Fixture',
        'GIT_AUTHOR_EMAIL' => 'fixture@example.test',
        'GIT_COMMITTER_NAME' => 'Fixture',
        'GIT_COMMITTER_EMAIL' => 'fixture@example.test',
        ...$env,
    ]);

    return $process->mustRun()->getOutput();
}

function commitAll(string $root, string $message, string $date): string
{
    gitInRepo($root, ['add', '-A']);
    gitInRepo($root, ['commit', '-m', $message, '--no-gpg-sign'], [
        'GIT_AUTHOR_DATE' => $date,
        'GIT_COMMITTER_DATE' => $date,
    ]);

    return trim(gitInRepo($root, ['rev-parse', 'HEAD']));
}

function phpUnitTestFile(string $class): string
{
    return <<<PHP
        <?php

        use PHPUnit\Framework\TestCase;

        class {$class} extends TestCase
        {
            public function test_something(): void
            {
                \$this->assertTrue(true);
            }
        }
        PHP;
}

beforeEach(function () {
    $this->root = base_path('storage/framework/testing/snapshot-repo');
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root.'/tests/Unit');

    gitInRepo($this->root, ['init', '-b', 'main']);

    File::put($this->root.'/composer.json', json_encode(['require' => ['laravel/framework' => '^9.0']]));
    File::put($this->root.'/tests/Unit/AlphaTest.php', phpUnitTestFile('AlphaTest'));
    $this->commitOnNine = commitAll($this->root, 'boot on laravel 9', '2022-01-10T10:00:00Z');

    File::put($this->root.'/tests/Unit/BetaTest.php', phpUnitTestFile('BetaTest'));
    $this->matureNine = commitAll($this->root, 'add beta test', '2022-09-01T10:00:00Z');

    File::put($this->root.'/composer.json', json_encode(['require' => ['laravel/framework' => '^10.0']]));
    $this->bumpToTen = commitAll($this->root, 'upgrade to laravel 10', '2023-02-20T10:00:00Z');

    File::put($this->root.'/tests/Unit/GammaTest.php', phpUnitTestFile('GammaTest'));
    $this->headSha = commitAll($this->root, 'add gamma test', '2023-06-15T10:00:00Z');

    $this->repository = Repository::create([
        'full_name' => 'acme/history',
        'owner' => 'acme',
        'name' => 'history',
        'url' => 'https://github.com/acme/history.git',
        'clone_path' => $this->root,
        'head_sha' => $this->headSha,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
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
    expect(trim(gitInRepo($this->root, ['rev-parse', 'HEAD'])))->toBe($this->headSha);
});

it('fails when no composer.json commit resolves to a Laravel major', function () {
    $bare = base_path('storage/framework/testing/no-laravel-repo');
    File::deleteDirectory($bare);
    File::ensureDirectoryExists($bare);
    gitInRepo($bare, ['init', '-b', 'main']);
    File::put($bare.'/composer.json', json_encode(['require' => ['symfony/console' => '^6.0']]));
    commitAll($bare, 'not a laravel app', '2022-01-01T10:00:00Z');

    Repository::create([
        'full_name' => 'acme/plain',
        'owner' => 'acme',
        'name' => 'plain',
        'url' => 'https://github.com/acme/plain.git',
        'clone_path' => $bare,
        'head_sha' => 'irrelevant',
    ]);

    $this->artisan('analyse:snapshot', ['full_name' => 'acme/plain'])->assertFailed();
    expect(Snapshot::count())->toBe(0);

    File::deleteDirectory($bare);
});
