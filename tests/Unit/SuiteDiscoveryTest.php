<?php

declare(strict_types=1);

use App\Analysis\Discovery\SuiteDiscovery;
use App\Analysis\Tree\WorkingTree;

function discoverInRoot(string $root): array
{
    return new SuiteDiscovery()->discover(new WorkingTree($root));
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/lta-suite-discovery-'.uniqid();
    mkdir($this->root, 0777, true);
});

afterEach(function () {
    deleteDirectoryRecursively($this->root);
});

function deleteDirectoryRecursively(string $directory): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

function writeRepoFile(string $root, string $relative, string $contents = "<?php\n"): void
{
    $path = $root.'/'.$relative;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

it('discovers testsuite directories declared in phpunit.xml', function () {
    writeRepoFile($this->root, 'phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                <testsuite name="Feature"><directory>./tests/Feature</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    writeRepoFile($this->root, 'tests/Unit/AlphaTest.php');
    writeRepoFile($this->root, 'tests/Feature/Deep/BetaTest.php');
    writeRepoFile($this->root, 'tests/Feature/Helper.php');       // wrong suffix — excluded
    writeRepoFile($this->root, 'tests/Browser/GammaTest.php');    // not declared — excluded
    writeRepoFile($this->root, 'tests/Unit/SomeTestCase.php');    // wrong suffix — excluded

    expect(discoverInRoot($this->root))->toBe([
        'tests/Feature/Deep/BetaTest.php',
        'tests/Unit/AlphaTest.php',
    ]);
});

it('honours a custom suffix attribute and prefers phpunit.xml over the dist file', function () {
    writeRepoFile($this->root, 'phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="All"><directory suffix=".spec.php">specs</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    writeRepoFile($this->root, 'phpunit.xml.dist', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="All"><directory>tests</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    writeRepoFile($this->root, 'specs/OrderSpec.spec.php');
    writeRepoFile($this->root, 'tests/IgnoredTest.php');

    expect(discoverInRoot($this->root))->toBe(['specs/OrderSpec.spec.php']);
});

it('falls back to tests/ with the default suffix when no phpunit config exists', function () {
    writeRepoFile($this->root, 'tests/Pest.php');                 // wrong suffix — excluded
    writeRepoFile($this->root, 'tests/Feature/LoginTest.php');
    writeRepoFile($this->root, 'src/NotATest.php');

    expect(discoverInRoot($this->root))->toBe(['tests/Feature/LoginTest.php']);
});

it('returns an empty list when declared directories are missing on disk', function () {
    writeRepoFile($this->root, 'phpunit.xml.dist', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests/unit</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);

    expect(discoverInRoot($this->root))->toBe([]);
});
