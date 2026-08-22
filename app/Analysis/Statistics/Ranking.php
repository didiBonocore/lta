<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Shared average-rank tie handling for the rank-based statistics (Mann-Whitney, Wilcoxon
 * signed-rank, Spearman, Mann-Kendall's tie correction). Extracted from MannWhitney so the
 * tie policy is implemented exactly once.
 */
final class Ranking
{
    /**
     * Average-rank assignment with tie handling: tied values all receive the mean of the
     * ranks they span (ranks are 1-based).
     *
     * @param  list<int|float>  $values
     * @return array<int, float> rank per input index
     */
    public static function averageRanks(array $values): array
    {
        $indexed = [];
        foreach ($values as $i => $v) {
            $indexed[] = ['i' => $i, 'v' => $v];
        }
        usort($indexed, static fn ($a, $b) => $a['v'] <=> $b['v']);

        $ranks = array_fill(0, count($values), 0.0);
        $k = 0;
        $count = count($indexed);
        while ($k < $count) {
            $j = $k;
            while ($j + 1 < $count && $indexed[$j + 1]['v'] === $indexed[$k]['v']) {
                $j++;
            }
            $avgRank = (float) ((($k + 1) + ($j + 1)) / 2); // ranks are 1-based
            for ($m = $k; $m <= $j; $m++) {
                $ranks[$indexed[$m]['i']] = $avgRank;
            }
            $k = $j + 1;
        }

        return $ranks;
    }

    /**
     * @param  list<int|float>  $values
     * @return list<int> sizes of tie groups with size > 1
     */
    public static function tieGroupSizes(array $values): array
    {
        $counts = [];
        foreach ($values as $v) {
            $key = (string) $v;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return array_values(array_filter($counts, static fn (int $c): bool => $c > 1));
    }
}
