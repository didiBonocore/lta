<?php

declare(strict_types=1);

namespace App\Analysis\Anonymisation;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The join between the named corpus and the anonymised measurements: full_name -> stable
 * alias (R01, R02, ...) plus the numeric repositories-table id, because one export keys on
 * the id rather than the name and both must resolve to the same alias.
 *
 * Once assigned, an alias never changes. The map is read if present and only repositories
 * not already in it receive new aliases, numbered on from the highest existing alias — never
 * renumbered, never sorted-and-reassigned, since either would silently invalidate every
 * figure and table already produced from an earlier run. On a first run assignment is in
 * ascending full_name order so the initial map is deterministic and reproducible from the
 * raw exports alone.
 */
final class AliasMap
{
    /** @var array<string, array{alias: string, repository_id: int|null}> keyed by full_name, in assignment order */
    private array $entries = [];

    public static function load(string $path): self
    {
        $map = new self;
        if (! is_file($path)) {
            return $map;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Could not open alias map {$path} for reading.");
        }

        $header = fgetcsv($handle, escape: '');
        if ($header !== ['full_name', 'alias', 'repository_id']) {
            fclose($handle);
            throw new RuntimeException("Alias map {$path} does not carry the expected full_name,alias,repository_id header.");
        }

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $fullName = (string) ($row[0] ?? '');
            $alias = (string) ($row[1] ?? '');
            $repositoryId = (string) ($row[2] ?? '');
            if ($fullName === '' || $alias === '') {
                continue;
            }
            $map->entries[$fullName] = [
                'alias' => $alias,
                'repository_id' => $repositoryId === '' ? null : (int) $repositoryId,
            ];
        }
        fclose($handle);

        return $map;
    }

    /**
     * Assign aliases to the names not already mapped, in ascending full_name order,
     * continuing the numbering from the highest existing alias. Returns the newly assigned
     * entries (full_name => alias) so the run can report them. $idsByName supplies the
     * repositories-table id per name where one exists; ids are also refreshed on existing
     * entries that lack one (a candidate acquired since the map was first written).
     *
     * @param  list<string>  $fullNames
     * @param  array<string, int>  $idsByName
     * @return array<string, string>
     */
    public function extend(array $fullNames, array $idsByName): array
    {
        $next = 1;
        foreach ($this->entries as $entry) {
            if (preg_match('/^R(\d+)$/', $entry['alias'], $m) === 1) {
                $next = max($next, (int) $m[1] + 1);
            }
        }

        foreach ($this->entries as $fullName => $entry) {
            if ($entry['repository_id'] === null && isset($idsByName[$fullName])) {
                $this->entries[$fullName]['repository_id'] = $idsByName[$fullName];
            }
        }

        $new = array_values(array_diff(array_unique($fullNames), array_keys($this->entries)));
        sort($new, SORT_STRING);

        $assigned = [];
        foreach ($new as $fullName) {
            $alias = sprintf('R%02d', $next++);
            $this->entries[$fullName] = [
                'alias' => $alias,
                'repository_id' => $idsByName[$fullName] ?? null,
            ];
            $assigned[$fullName] = $alias;
        }

        return $assigned;
    }

    public function aliasFor(string $fullName): ?string
    {
        return $this->entries[$fullName]['alias'] ?? null;
    }

    public function aliasForId(int $repositoryId): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['repository_id'] === $repositoryId) {
                return $entry['alias'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function fullNames(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Every distinct owner and repo segment across the map, for the gate's name rule.
     *
     * @return list<string>
     */
    public function segments(): array
    {
        $segments = [];
        foreach (array_keys($this->entries) as $fullName) {
            foreach (explode('/', $fullName) as $segment) {
                if ($segment !== '') {
                    $segments[$segment] = true;
                }
            }
        }

        return array_keys($segments);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function save(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new RuntimeException("Could not open alias map {$path} for writing.");
        }

        fputcsv($handle, ['full_name', 'alias', 'repository_id'], escape: '');
        foreach ($this->entries as $fullName => $entry) {
            fputcsv($handle, [$fullName, $entry['alias'], $entry['repository_id'] ?? ''], escape: '');
        }
        fclose($handle);
    }
}
