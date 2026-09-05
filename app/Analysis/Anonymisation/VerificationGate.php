<?php

declare(strict_types=1);

namespace App\Analysis\Anonymisation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * The verification gate behind analyse:anonymise: after every output file is written, the
 * whole output directory is re-read and the run fails if anything that could tie a
 * measurement back to a named repository survives — a repository name (or either of its
 * owner/repo segments), a commit SHA, a URL scheme or host, or an author email. The gate is
 * the deliverable as much as the transformation is: a transformation bug produces a wrong
 * table, a gate bug produces a published file that identifies the corpus.
 *
 * Exemptions are explicit and per path: '*' exempts a file entirely (the named-by-design
 * corpus lists, the alias map when deliberately emitted), a list of rule numbers exempts
 * those rules, and a ['values' => [...]] scope exempts specific matched strings only (the
 * README's own provenance SHA — the value is exempt, not the rule).
 *
 * The SHA rule is deliberately narrower than "any 7+ hex characters": a run of 40 or more
 * hex characters is a SHA (or another content hash) unconditionally, while shorter runs
 * (7-39) must contain at least one letter a-f and one digit — pure-digit runs of that
 * length are overwhelmingly statistics (Mann-Whitney U values in these exports), not
 * abbreviated SHAs.
 */
final class VerificationGate
{
    /** Candidate hex runs, bounded so a run inside a longer alphanumeric token is ignored. */
    private const string HEX_RUN = '/(?<![0-9A-Za-z])[0-9a-fA-F]{7,}(?![0-9A-Za-z])/';

    private const string URL_RULE = '/github\.com|https?:\/\/|git@/i';

    private const string EMAIL_RULE = '/[\w.-]+@[\w.-]+\.\w+/';

    public const array RULE_LABELS = [
        1 => 'repository name',
        2 => 'commit SHA',
        3 => 'URL or host',
        4 => 'email address',
    ];

    /** @var non-empty-string|null case-insensitive alternation of names and segments */
    private readonly ?string $namePattern;

    /**
     * @param  list<string>  $fullNames  every owner/repo in the alias map
     * @param  list<string>  $segments  every distinct owner and repo segment of those names
     */
    public function __construct(array $fullNames, array $segments)
    {
        $needles = array_values(array_unique(array_filter([...$fullNames, ...$segments])));
        usort($needles, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->namePattern = $needles === [] ? null : '/(?<![\w.-])(?:'
            .implode('|', array_map(fn (string $n): string => preg_quote($n, '/'), $needles))
            .')(?![\w.-])/i';
    }

    /**
     * Scan every file under $directory. $exemptions maps a path relative to $directory to a
     * scope: '*' (whole file), a list of rule numbers, or ['values' => list of exact matched
     * strings to allow].
     *
     * @param  array<string, mixed>  $exemptions
     * @return Collection<int, array{file: string, line: int, rule: int, match: string}>
     */
    public function scan(string $directory, array $exemptions = []): Collection
    {
        $findings = new Collection;

        foreach (File::allFiles($directory) as $file) {
            $relative = $file->getRelativePathname();
            $scope = $exemptions[$relative] ?? null;
            if ($scope === '*') {
                continue;
            }

            foreach ($this->scanFile($file->getPathname()) as $finding) {
                if ($this->isExempt($finding, $scope)) {
                    continue;
                }
                $finding['file'] = $relative;
                $findings->push($finding);
            }
        }

        return $findings;
    }

    /**
     * Apply the four rules to one file, line by line, so reported line numbers are physical.
     * Binary files are skipped (relevant to --scan-repo, which walks the whole tree).
     *
     * @return Collection<int, array{file: string, line: int, rule: int, match: string}>
     */
    public function scanFile(string $path): Collection
    {
        $findings = new Collection;

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $findings;
        }

        $probe = fread($handle, 8192);
        if ($probe === false || str_contains($probe, "\0")) {
            fclose($handle);

            return $findings;
        }
        rewind($handle);

        $number = 0;
        while (($line = fgets($handle)) !== false) {
            $number++;
            foreach ($this->matchLine($line) as [$rule, $match]) {
                $findings->push(['file' => $path, 'line' => $number, 'rule' => $rule, 'match' => $match]);
            }
        }
        fclose($handle);

        return $findings;
    }

    /**
     * @return list<array{int, string}>
     */
    private function matchLine(string $line): array
    {
        $hits = [];

        if ($this->namePattern !== null && preg_match_all($this->namePattern, $line, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $hits[] = [1, $match];
            }
        }

        if (preg_match_all(self::HEX_RUN, $line, $matches) > 0) {
            foreach ($matches[0] as $run) {
                if ($this->isShaLike($run)) {
                    $hits[] = [2, $run];
                }
            }
        }

        if (preg_match_all(self::URL_RULE, $line, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $hits[] = [3, $match];
            }
        }

        if (preg_match_all(self::EMAIL_RULE, $line, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $hits[] = [4, $match];
            }
        }

        return $hits;
    }

    /**
     * A run of 40+ characters is a full SHA (or another content hash) regardless of
     * composition. Shorter runs must mix at least one hex letter with at least one digit —
     * pure-digit runs are statistics.
     */
    private function isShaLike(string $run): bool
    {
        if (strlen($run) >= 40) {
            return true;
        }

        return preg_match('/[a-fA-F]/', $run) === 1
            && preg_match('/\d/', $run) === 1;
    }

    /**
     * @param  array{file: string, line: int, rule: int, match: string}  $finding
     */
    private function isExempt(array $finding, mixed $scope): bool
    {
        if (! is_array($scope)) {
            return false;
        }

        if (in_array($finding['rule'], $scope, true)) {
            return true;
        }

        $values = $scope['values'] ?? [];

        return is_array($values) && in_array($finding['match'], $values, true);
    }
}
