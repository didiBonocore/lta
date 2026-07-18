<?php

declare(strict_types=1);

namespace App\Analysis\Discovery;

use Symfony\Component\Finder\Finder;

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
    public function discover(string $repositoryRoot): array
    {
        $paths = [];
        foreach ($this->suiteDirectories($repositoryRoot) as [$directory, $suffix]) {
            $absolute = $repositoryRoot.DIRECTORY_SEPARATOR.$directory;
            if (! is_dir($absolute)) {
                continue;
            }

            $finder = new Finder()->files()->in($absolute)->name('*'.$suffix);
            foreach ($finder as $file) {
                $paths[] = $directory.DIRECTORY_SEPARATOR.$file->getRelativePathname();
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * @return list<array{0: string, 1: string}> [directory relative to root, filename suffix]
     */
    private function suiteDirectories(string $repositoryRoot): array
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            $configPath = $repositoryRoot.DIRECTORY_SEPARATOR.$candidate;
            if (! is_file($configPath)) {
                continue;
            }

            $declared = $this->directoriesFromConfig($configPath);
            if ($declared !== []) {
                return $declared;
            }
        }

        return [['tests', self::DEFAULT_SUFFIX]];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function directoriesFromConfig(string $configPath): array
    {
        $xml = @simplexml_load_string((string) file_get_contents($configPath));
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
