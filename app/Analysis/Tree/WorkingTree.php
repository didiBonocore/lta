<?php

declare(strict_types=1);

namespace App\Analysis\Tree;

use Symfony\Component\Finder\Finder;

/**
 * The checked-out working tree of a clone — what HEAD extraction (M1) reads.
 */
final class WorkingTree implements SourceTree
{
    public function __construct(private readonly string $root) {}

    public function files(): array
    {
        $paths = [];
        foreach (new Finder()->files()->in($this->root) as $file) {
            $paths[] = str_replace('\\', '/', $file->getRelativePathname());
        }
        sort($paths);

        return $paths;
    }

    public function read(string $path): ?string
    {
        $absolute = $this->root.DIRECTORY_SEPARATOR.$path;
        if (! is_file($absolute)) {
            return null;
        }

        return (string) file_get_contents($absolute);
    }
}
