<?php

declare(strict_types=1);

namespace App\Analysis\Tree;

use Illuminate\Support\Facades\Process;

/**
 * A repository's tree at a specific commit, read via `git show <sha>:path` so no checkout
 * ever mutates the clone (Instrument A reads many shas from one clone).
 */
final class GitTree implements SourceTree
{
    public function __construct(
        private readonly string $root,
        private readonly string $sha,
    ) {}

    public function files(): array
    {
        $output = Process::path($this->root)
            ->run(['git', 'ls-tree', '-r', '--name-only', '-z', $this->sha])
            ->throw()
            ->output();

        $paths = array_values(array_filter(explode("\0", $output), static fn (string $p): bool => $p !== ''));
        sort($paths);

        return $paths;
    }

    public function read(string $path): ?string
    {
        $result = Process::path($this->root)->run(['git', 'show', "{$this->sha}:{$path}"]);

        return $result->successful() ? $result->output() : null;
    }
}
