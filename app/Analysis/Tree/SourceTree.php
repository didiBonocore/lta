<?php

declare(strict_types=1);

namespace App\Analysis\Tree;

/**
 * A read-only view of a mined repository's file tree at one point in time. The working tree
 * at HEAD (M1) and an arbitrary commit read via `git show` (M2) expose the same surface, so
 * discovery and extraction never care which instrument is running.
 */
interface SourceTree
{
    /** @return list<string> every file path in the tree, repo-relative with forward slashes */
    public function files(): array;

    /** File contents, or null if the path does not exist in this tree. */
    public function read(string $path): ?string;
}
