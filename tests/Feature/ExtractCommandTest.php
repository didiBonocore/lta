<?php

declare(strict_types=1);

use App\Models\ParseFailure;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\File;
use Tests\Support\GitFixtureRepo;

/**
 * The fake "cloned" repository is assembled from the M0 gold-standard fixtures, so every
 * expected value below is already hand-computed and fixture-proven.
 */
beforeEach(function () {
    /** @var \Tests\TestCase $this */
    $this->root = base_path('storage/framework/testing/extract-repo');
    File::deleteDirectory($this->root);

    File::ensureDirectoryExists($this->root.'/tests/Feature');
    File::ensureDirectoryExists($this->root.'/tests/Unit');
    File::put($this->root.'/phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    File::copy(base_path('tests/Fixtures/PhpUnit/FeatureLoginExample.php'), $this->root.'/tests/Feature/LoginTest.php');
    File::copy(base_path('tests/Fixtures/Pest/UnitGatewayExample.php'), $this->root.'/tests/Unit/GatewayTest.php');

    $this->repository = Repository::create([
        'full_name' => 'acme/shop',
        'owner' => 'acme',
        'name' => 'shop',
        'url' => 'https://github.com/acme/shop.git',
        'clone_path' => $this->root,
        'head_sha' => 'deadbeef',
    ]);
});

afterEach(function () {
    /** @var \Tests\TestCase $this */
    File::deleteDirectory($this->root);
});

it('extracts HEAD into a head snapshot with one observation per test method', function () {
    /** @var \Tests\TestCase $this */
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])
        ->assertSuccessful();

    $snapshot = Snapshot::query()->sole();
    expect($snapshot->kind)->toBe('head')
        ->and($snapshot->commit_sha)->toBe('deadbeef')
        ->and($snapshot->framework_version)->toBeNull();

    expect(TestObservation::query()->count())->toBe(2);

    $login = TestObservation::query()->where('front_end', 'phpunit')->sole();
    expect($login->file_path)->toBe('tests/Feature/LoginTest.php')
        ->and($login->identifier)->toBe('test_user_can_login')
        ->and($login->test_type)->toBe('feature')
        ->and($login->test_assertion_count)->toBe(2)
        ->and($login->mock_assertion_count)->toBe(0)
        ->and($login->total_assertion_count)->toBe(2)
        ->and($login->mock_assertion_ratio)->toBe(0.0)
        ->and($login->mock_breadth)->toBe(0)
        ->and($login->uses_refresh_database)->toBeTrue()
        ->and($login->size_statements)->toBe(3)
        ->and($login->start_line)->toBe(21)   // definition range in the fixture file,
        ->and($login->end_line)->toBe(31);    // recorded for the blame pass's git log -L

    $gateway = TestObservation::query()->where('front_end', 'pest')->sole();
    expect($gateway->file_path)->toBe('tests/Unit/GatewayTest.php')
        ->and($gateway->identifier)->toBe('charges via a mocked gateway')
        ->and($gateway->test_type)->toBe('unit')
        ->and($gateway->test_assertion_count)->toBe(1)
        ->and($gateway->mock_assertion_count)->toBe(1)
        ->and($gateway->total_assertion_count)->toBe(2)
        ->and($gateway->mock_assertion_ratio)->toBe(0.5)
        ->and($gateway->mock_breadth)->toBe(1)
        ->and($gateway->max_mock_chain_depth)->toBe(4)
        ->and($gateway->mock_kinds)->toBe(['container']);

    expect($this->repository->refresh()->primary_test_framework)->toBe('mixed');
});

it('is idempotent: re-extracting replaces rather than duplicates observations', function () {
    /** @var \Tests\TestCase $this */
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])->assertSuccessful();
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])->assertSuccessful();

    expect(Snapshot::query()->count())->toBe(1)
        ->and(TestObservation::query()->count())->toBe(2);
});

it('survives an unparsable test file, records it, and keeps extracting the rest', function () {
    /** @var \Tests\TestCase $this */
    File::put($this->root.'/tests/Unit/BrokenTest.php', "<?php\n\nclass BrokenTest extends TestCase {\n    public function test_broken() { \$x = ;\n");

    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])
        ->assertSuccessful();

    expect(TestObservation::query()->count())->toBe(2);

    $failure = ParseFailure::query()->sole();
    expect($failure->file_path)->toBe('tests/Unit/BrokenTest.php')
        ->and($failure->commit_sha)->toBe('deadbeef')
        ->and($failure->repository_id)->toBe($this->repository->id)
        ->and($failure->message)->not->toBe('');

    // Re-running replaces the failure log too — no duplicates.
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])->assertSuccessful();
    expect(ParseFailure::query()->count())->toBe(1);
});

it('extracts from a synthetic git repository built on the fly', function () {
    /** @var \Tests\TestCase $this */
    $repo = GitFixtureRepo::init(base_path('storage/framework/testing/extract-git-repo'));
    $repo->write('phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="All"><directory>tests</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    $repo->write('tests/Feature/LoginTest.php', (string) file_get_contents(base_path('tests/Fixtures/PhpUnit/FeatureLoginExample.php')));
    $repo->write('tests/Unit/GatewayTest.php', (string) file_get_contents(base_path('tests/Fixtures/Pest/UnitGatewayExample.php')));
    $headSha = $repo->commit('two fixture-style tests', '2024-01-01T10:00:00Z');

    Repository::create([
        'full_name' => 'acme/git-shop',
        'owner' => 'acme',
        'name' => 'git-shop',
        'url' => 'https://github.com/acme/git-shop.git',
        'clone_path' => $repo->root,
        'head_sha' => $headSha,
    ]);

    $this->artisan('analyse:extract', ['full_name' => 'acme/git-shop', '--head' => true])
        ->assertSuccessful();

    $observations = TestObservation::query()->whereRelation('repository', 'full_name', 'acme/git-shop')->get();
    expect($observations)->toHaveCount(2)
        ->and($observations->firstWhere('front_end', 'phpunit')->file_path)->toBe('tests/Feature/LoginTest.php')
        ->and($observations->firstWhere('front_end', 'pest')->file_path)->toBe('tests/Unit/GatewayTest.php')
        ->and(Snapshot::query()->whereRelation('repository', 'full_name', 'acme/git-shop')->sole()->commit_sha)->toBe($headSha);

    $repo->destroy();
});

it('fails when the repository has not been acquired', function () {
    /** @var \Tests\TestCase $this */
    $this->artisan('analyse:extract', ['full_name' => 'nobody/nothing', '--head' => true])
        ->assertFailed();
});

it('fails without --head when no version-boundary snapshots exist yet', function () {
    /** @var \Tests\TestCase $this */
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop'])->assertFailed();
});
