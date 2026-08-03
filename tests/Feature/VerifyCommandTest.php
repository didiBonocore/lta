<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use App\Models\UnroutableFile;

function seedVerifiableRepository(string $fullName = 'acme/healthy'): Repository
{
    $repository = Repository::create([
        'full_name' => $fullName,
        'owner' => explode('/', $fullName)[0],
        'name' => explode('/', $fullName)[1],
        'url' => "https://github.com/{$fullName}.git",
    ]);

    foreach ([9 => 'aaa', 10 => 'bbb'] as $major => $sha) {
        $snapshot = Snapshot::create([
            'repository_id' => $repository->id,
            'commit_sha' => $sha,
            'framework_version' => $major,
            'kind' => 'version_boundary',
            'commit_date' => "202{$major}-01-01",
        ]);
        TestObservation::create([
            'snapshot_id' => $snapshot->id,
            'repository_id' => $repository->id,
            'file_path' => 'tests/ExampleTest.php',
            'identifier' => "test_{$major}",
            'front_end' => 'phpunit',
            'test_type' => 'unit',
            'introduced_commit_sha' => 'ccc',
            'introduced_author_date' => '2021-01-01',
            'ai_window' => 'pre',
        ]);
    }

    return $repository;
}

it('passes a healthy repository and reports coverage and failure rates', function () {
    seedVerifiableRepository();

    $this->artisan('analyse:verify', ['full_name' => 'acme/healthy'])
        ->expectsOutputToContain('9 → 10')
        ->expectsOutputToContain('0.0% unattributed')
        ->expectsOutputToContain('0.00%')
        ->expectsOutputToContain('All hard checks passed.')
        ->assertSuccessful();
});

it('warns on empty snapshots and an unblamed repository without failing', function () {
    $repository = seedVerifiableRepository();
    Snapshot::create([
        'repository_id' => $repository->id,
        'commit_sha' => 'ddd',
        'framework_version' => 11,
        'kind' => 'version_boundary',
    ]);
    TestObservation::query()->update(['ai_window' => null, 'introduced_author_date' => null]);

    $this->artisan('analyse:verify', ['full_name' => 'acme/healthy'])
        ->expectsOutputToContain('empty snapshots')
        ->expectsOutputToContain('blame has not run')
        ->assertSuccessful();
});

it('hard-fails on a repository with snapshots but no observations', function () {
    $repository = seedVerifiableRepository();
    TestObservation::query()->delete();

    $this->artisan('analyse:verify', ['full_name' => 'acme/healthy'])
        ->expectsOutputToContain('hard failure')
        ->assertFailed();
});

it('hard-fails on cross-linked orphan rows that foreign keys cannot catch', function () {
    $healthy = seedVerifiableRepository();
    $other = seedVerifiableRepository('acme/other');

    // An observation attached to acme/healthy's snapshot but claiming acme/other's
    // repository: every FK holds, yet the row is corrupt.
    TestObservation::create([
        'snapshot_id' => $healthy->snapshots()->first()->id,
        'repository_id' => $other->id,
        'file_path' => 'tests/OrphanTest.php',
        'identifier' => 'test_orphan',
        'front_end' => 'phpunit',
        'test_type' => 'unit',
    ]);

    $this->artisan('analyse:verify', ['full_name' => 'acme/healthy'])
        ->expectsOutputToContain('orphan row')
        ->assertFailed();
});

it('surfaces the default branch alongside the HEAD sha', function () {
    seedVerifiableRepository()->update(['head_sha' => 'abc123def456789', 'default_branch' => 'develop']);

    $this->artisan('analyse:verify', ['full_name' => 'acme/healthy'])
        ->expectsOutputToContain('HEAD abc123def456 on develop')
        ->assertSuccessful();
});

it('warns when an empty snapshot has unroutable files, and reports the per-major counts', function () {
    $repository = seedVerifiableRepository();
    $codeceptionEra = Snapshot::create([
        'repository_id' => $repository->id,
        'commit_sha' => 'eee',
        'framework_version' => 5,
        'kind' => 'version_boundary',
    ]);
    foreach (['tests/unit/BaseTest.php', 'tests/_support/UnitTester.php'] as $path) {
        UnroutableFile::create([
            'repository_id' => $repository->id,
            'snapshot_id' => $codeceptionEra->id,
            'file_path' => $path,
            'commit_sha' => 'eee',
            'base_class' => 'Codeception\TestCase\Test',
        ]);
    }

    // One substring per output line: a line is consumed by the first matching expectation,
    // so asserting two fragments of the same table row would leave the second unmatched.
    $this->artisan('analyse:verify', ['full_name' => 'acme/healthy'])
        ->expectsOutputToContain('major 5 has 2 unroutable file(s) and 0 parse failure(s) but 0 observations')
        ->expectsOutputToContain('major 5: 2')
        ->assertSuccessful();
});

it('warns when the constraint oscillates on trunk (first-parent positions out of major order)', function () {
    $repository = seedVerifiableRepository();
    // Major 9's representative sits AFTER major 10's in the first-parent walk: 9 → 10 → 9.
    $repository->snapshots()->where('framework_version', 9)->update(['first_parent_index' => 7]);
    $repository->snapshots()->where('framework_version', 10)->update(['first_parent_index' => 4]);

    // Single expectation: the check name and the positions share one table row line.
    $this->artisan('analyse:verify', ['full_name' => 'acme/healthy'])
        ->expectsOutputToContain('trunk positions by major: 9@7, 10@4')
        ->assertSuccessful();
});

it('fails when no repository matches', function () {
    $this->artisan('analyse:verify', ['full_name' => 'nobody/nothing'])->assertFailed();
});
