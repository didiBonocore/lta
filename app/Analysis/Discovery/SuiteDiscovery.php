<?php

declare(strict_types=1);

namespace App\Analysis\Discovery;

use App\Analysis\Tree\SourceTree;

/**
 * Locates a mined repository's test files the way its own runner would: from the
 * testsuite directories declared in phpunit.xml[.dist] (Pest reads the same file), falling
 * back to a conventional tests/ directory. Only discovery lives here — routing a file to a
 * front-end is done by FrontEnd::handles() on content, never on path.
 */
final class SuiteDiscovery
{
    private const string DEFAULT_SUFFIX = 'Test.php';

    /**
     * @return list<string> repo-relative test file paths, sorted
     */
    public function discover(SourceTree $tree): array
    {
        $suites = $this->suiteDirectories($tree);

        $paths = [];
        foreach ($tree->files() as $path) {
            foreach ($suites as [$directory, $suffix]) {
                if (str_starts_with($path, $directory.'/') && str_ends_with($path, $suffix)) {
                    $paths[] = $path;

                    break;
                }
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return list<array{0: string, 1: string}> [directory relative to root, filename suffix]
     */
    private function suiteDirectories(SourceTree $tree): array
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            $config = $tree->read($candidate);
            if ($config === null) {
                continue;
            }

            $declared = $this->directoriesFromConfig($config);
            if ($declared !== []) {
                return $declared;
            }
        }

        return [['tests', self::DEFAULT_SUFFIX]];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function directoriesFromConfig(string $config): array
    {
        $xml = @simplexml_load_string($config);
        if ($xml === false) {
            return [];
        }

        $directories = [];
        foreach ($xml->xpath('//testsuites/testsuite/directory') ?: [] as $node) {
            $directory = trim((string) $node);
            if ($directory === '') {
                continue;
            }
            $suffix = trim((string) ($node['suffix'] ?? '')) ?: self::DEFAULT_SUFFIX;
            $directories[] = [$this->normalise($directory), $suffix];
        }

        return $directories;
    }

    private function normalise(string $directory): string
    {
        $directory = str_replace('\\', '/', $directory);
        $directory = ltrim($directory, './');

        return rtrim($directory, '/');
    }
}
