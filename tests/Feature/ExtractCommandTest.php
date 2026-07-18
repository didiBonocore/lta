<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\File;

/**
 * The fake "cloned" repository is assembled from the M0 gold-standard fixtures, so every
 * expected value below is already hand-computed and fixture-proven.
 */
beforeEach(function () {
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
    File::deleteDirectory($this->root);
});

it('extracts HEAD into a head snapshot with one observation per test method', function () {
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])
        ->assertSuccessful();

    $snapshot = Snapshot::sole();
    expect($snapshot->kind)->toBe('head')
        ->and($snapshot->commit_sha)->toBe('deadbeef')
        ->and($snapshot->framework_version)->toBeNull();

    expect(TestObservation::count())->toBe(2);

    $login = TestObservation::where('front_end', 'phpunit')->sole();
    expect($login->file_path)->toBe('tests/Feature/LoginTest.php')
        ->and($login->identifier)->toBe('test_user_can_login')
        ->and($login->test_type)->toBe('feature')
        ->and($login->assertion_count)->toBe(2)
        ->and($login->mock_breadth)->toBe(0)
        ->and($login->uses_refresh_database)->toBeTrue()
        ->and($login->size_statements)->toBe(3);

    $gateway = TestObservation::where('front_end', 'pest')->sole();
    expect($gateway->file_path)->toBe('tests/Unit/GatewayTest.php')
        ->and($gateway->identifier)->toBe('charges via a mocked gateway')
        ->and($gateway->test_type)->toBe('unit')
        ->and($gateway->assertion_count)->toBe(1)
        ->and($gateway->mock_breadth)->toBe(1)
        ->and($gateway->max_mock_chain_depth)->toBe(4)
        ->and($gateway->mock_kinds)->toBe(['container']);

    expect($this->repository->refresh()->primary_test_framework)->toBe('mixed');
});

it('is idempotent: re-extracting replaces rather than duplicates observations', function () {
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])->assertSuccessful();
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])->assertSuccessful();

    expect(Snapshot::count())->toBe(1)
        ->and(TestObservation::count())->toBe(2);
});

it('survives an unparsable test file and keeps extracting the rest', function () {
    File::put($this->root.'/tests/Unit/BrokenTest.php', "<?php\n\nclass BrokenTest extends TestCase {\n    public function test_broken() { \$x = ;\n");

    $this->artisan('analyse:extract', ['full_name' => 'acme/shop', '--head' => true])
        ->assertSuccessful();

    expect(TestObservation::count())->toBe(2);
});

it('fails when the repository has not been acquired', function () {
    $this->artisan('analyse:extract', ['full_name' => 'nobody/nothing', '--head' => true])
        ->assertFailed();
});

it('fails without --head when no version-boundary snapshots exist yet', function () {
    $this->artisan('analyse:extract', ['full_name' => 'acme/shop'])->assertFailed();
});
