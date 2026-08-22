<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Ir\Enums\TestType;
use App\Analysis\Statistics\CohenKappa;
use App\Models\TestObservation;
use Illuminate\Console\Command;

/**
 * Appendix C's classifier-validation protocol, step 2: read the hand-labelled sample back
 * (as produced by analyse:sample-types), join on observation id, and score the classifier
 * against the human gold standard — Cohen's kappa with its Landis & Koch band, the
 * confusion matrix (exported as CSV next to the input), and the per-category disagreement
 * breakdown the protocol promises.
 */
class ScoreTypesCommand extends Command
{
    protected $signature = 'analyse:score-types {path : the labelled sample CSV}';

    protected $description = 'Score the test-type classifier against hand labels: kappa, confusion matrix, disagreements';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readLabelledCsv($path);
        if ($rows === null) {
            return self::FAILURE;
        }

        $validLabels = array_map(fn (TestType $t): string => $t->value, TestType::cases());
        $classifier = [];
        $human = [];
        $unlabelled = 0;

        foreach ($rows as $row) {
            $label = strtolower(trim($row['human_label']));
            if ($label === '') {
                $unlabelled++;

                continue;
            }
            if (! in_array($label, $validLabels, true)) {
                $this->error("Row id {$row['id']}: unknown label '{$label}' — expected one of ".implode(', ', $validLabels).'.');

                return self::FAILURE;
            }

            $observation = TestObservation::query()->find((int) $row['id']);
            if ($observation === null) {
                $this->error("Row id {$row['id']} does not match any test observation — was the dataset re-mined since sampling?");

                return self::FAILURE;
            }

            $classifier[] = $observation->test_type;
            $human[] = $label;
        }

        if ($unlabelled > 0) {
            $this->warn("{$unlabelled} row(s) left unlabelled — skipped.");
        }
        if ($classifier === []) {
            $this->error('No labelled rows to score.');

            return self::FAILURE;
        }

        $kappa = CohenKappa::kappa($classifier, $human);
        $this->components->info(sprintf(
            "Cohen's kappa = %.4f (%s, Landis & Koch) over %d labelled observations",
            $kappa,
            CohenKappa::interpret($kappa),
            count($human),
        ));

        $this->renderConfusionMatrix($path, $classifier, $human, $validLabels);
        $this->renderDisagreements($classifier, $human, $validLabels);

        return self::SUCCESS;
    }

    /**
     * @return list<array{id: string, human_label: string}>|null
     */
    private function readLabelledCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open {$path} for reading.");

            return null;
        }

        $header = fgetcsv($handle);
        if ($header === false || ! in_array('id', $header, true) || ! in_array('human_label', $header, true)) {
            fclose($handle);
            $this->error('The CSV must carry the id and human_label columns produced by analyse:sample-types.');

            return null;
        }

        $idIndex = (int) array_search('id', $header, true);
        $labelIndex = (int) array_search('human_label', $header, true);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = [
                'id' => (string) ($row[$idIndex] ?? ''),
                'human_label' => (string) ($row[$labelIndex] ?? ''),
            ];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Confusion matrix, human labels as rows and classifier labels as columns, exported to
     * <input>_confusion.csv.
     *
     * @param  list<string>  $classifier
     * @param  list<string>  $human
     * @param  list<string>  $labels
     */
    private function renderConfusionMatrix(string $path, array $classifier, array $human, array $labels): void
    {
        $matrix = [];
        foreach ($labels as $row) {
            foreach ($labels as $column) {
                $matrix[$row][$column] = 0;
            }
        }
        foreach ($human as $i => $humanLabel) {
            $matrix[$humanLabel][$classifier[$i]]++;
        }

        $header = ['human \ classifier', ...$labels];
        $rows = [];
        foreach ($labels as $label) {
            $rows[] = [$label, ...array_values($matrix[$label])];
        }
        $this->table($header, $rows);

        $exportPath = (preg_replace('/\.csv$/i', '', $path) ?? $path).'_confusion.csv';
        $handle = fopen($exportPath, 'w');
        if ($handle === false) {
            $this->warn("Could not open {$exportPath} for writing — confusion matrix not exported.");

            return;
        }
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        $this->line("  exported: {$exportPath}");
    }

    /**
     * Per-category disagreement breakdown: for each human label, how often the classifier
     * agreed and where its misses went.
     *
     * @param  list<string>  $classifier
     * @param  list<string>  $human
     * @param  list<string>  $labels
     */
    private function renderDisagreements(array $classifier, array $human, array $labels): void
    {
        $rows = [];
        foreach ($labels as $label) {
            $indexes = array_keys($human, $label, true);
            if ($indexes === []) {
                continue;
            }

            $misses = [];
            $agreements = 0;
            foreach ($indexes as $i) {
                if ($classifier[$i] === $label) {
                    $agreements++;
                } else {
                    $misses[$classifier[$i]] = ($misses[$classifier[$i]] ?? 0) + 1;
                }
            }
            arsort($misses);

            $rows[] = [
                $label,
                count($indexes),
                $agreements,
                count($indexes) - $agreements,
                implode(', ', array_map(
                    fn (string $to, int $count): string => "classified {$to} ×{$count}",
                    array_keys($misses),
                    array_values($misses),
                )) ?: '—',
            ];
        }

        $this->table(['human label', 'n', 'agreements', 'disagreements', 'where the misses went'], $rows);
    }
}
