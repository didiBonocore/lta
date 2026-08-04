<?php

declare(strict_types=1);

namespace App\Analysis\Versioning;

use Illuminate\Support\Facades\Process;

/**
 * The first-parent composer.json walk shared by Instrument A (analyse:snapshot) and
 * candidate screening (analyse:screen). Both must count Laravel majors by the SAME rule:
 * if screening and mining disagreed about how many majors a repository spans, the corpus
 * would be defined by one rule and measured by another.
 *
 * Walks `git log --first-parent --follow --reverse -- composer.json` from HEAD, reads
 * composer.json at each touching commit via `git show`, resolves the laravel/framework
 * (fallback illuminate/support) constraint to an integer major, and selects the LAST trunk
 * commit per major — the mature state before the upgrade.
 */
final class TrunkMajorWalk
{
    public function __construct(private LaravelMajorResolver $resolver = new LaravelMajorResolver) {}

    /**
     * The representative commit per integer major, keyed and sorted by major. $onSkip is
     * invoked with a human-readable reason for each touching commit whose constraint is
     * absent or unresolvable.
     *
     * @param  (callable(string): void)|null  $onSkip
     * @return array<int, array{sha: string, date: string, major: int, constraint: string, index: int}>
     */
    public function representatives(string $root, ?callable $onSkip = null): array
    {
        $representatives = [];
        foreach ($this->touches($root, $onSkip) as $touch) {
            $representatives[$touch['major']] = $touch;
        }
        ksort($representatives);

        return $representatives;
    }

    /**
     * Every composer.json-touching commit on the first-parent line that resolves to a
     * Laravel major, oldest first. The index is the commit's position in that walk, recorded
     * so major ordering can be checked against trunk position rather than author date.
     *
     * @param  (callable(string): void)|null  $onSkip
     * @return list<array{sha: string, date: string, major: int, constraint: string, index: int}>
     */
    public function touches(string $root, ?callable $onSkip = null): array
    {
        $log = Process::path($root)
            ->run(['git', 'log', '--first-parent', '--follow', '--reverse', '--format=%H %aI', '--', 'composer.json'])
            ->throw()
            ->output();

        $touches = [];
        $index = 0;
        foreach (array_filter(explode("\n", trim($log))) as $line) {
            [$sha, $date] = explode(' ', trim($line), 2);
            $walkIndex = $index++;

            $constraint = $this->constraintAt($root, $sha);
            $major = $constraint !== null ? $this->resolver->resolve($constraint) : null;

            if ($constraint === null || $major === null) {
                if ($onSkip !== null) {
                    $onSkip($constraint === null
                        ? "Skipping {$sha}: composer.json absent or framework constraint missing."
                        : "Skipping {$sha}: framework constraint \"{$constraint}\" unparseable.");
                }

                continue;
            }

            $touches[] = [
                'sha' => $sha,
                'date' => $date,
                'major' => $major,
                'constraint' => $constraint,
                'index' => $walkIndex,
            ];
        }

        return $touches;
    }

    /**
     * The raw laravel/framework (fallback illuminate/support) constraint in composer.json at
     * a commit, read from the tree via `git show`; null where the file or constraint is absent.
     */
    public function constraintAt(string $root, string $sha): ?string
    {
        $shown = Process::path($root)->run(['git', 'show', "{$sha}:composer.json"]);
        if (! $shown->successful()) {
            return null; // composer.json absent at this commit (e.g. the rename source)
        }

        $composer = json_decode($shown->output(), true);
        if (! is_array($composer) || ! is_array($composer['require'] ?? null)) {
            return null;
        }

        $constraint = $composer['require']['laravel/framework']
            ?? $composer['require']['illuminate/support']
            ?? null;

        return is_string($constraint) ? $constraint : null;
    }
}
