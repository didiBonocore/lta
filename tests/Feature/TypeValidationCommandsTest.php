<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\GitFixtureRepo;
use Tests\TestCase;

/**
 * Appendix C's classifier-validation protocol, end to end: a seeded reproducible sample
 * that leaks no classifier output, and a scoring pass with a hand-computed kappa.
 */

/** Quote-aware CSV reader — source excerpts legitimately span lines. @return list<list<string>> */
function typeCsvRows(string $path): array
{
    $rows = [];
    $handle = fopen($path, 'r');
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

beforeEach(function () {
    /** @var TestCase $this */
    $this->repo = GitFixtureRepo::init(base_path('storage/framework/testing/types-repo'));
    $this->repo->write('tests/Unit/SampledTest.php', GitFixtureRepo::phpUnitTestClass('SampledTest', 'test_sampled'));
    $this->repo->commit('sampled test', '2023-01-01T10:00:00Z');

    $this->repository = Repository::create([
        'full_name' => 'acme/types',
        'owner' => 'acme',
        'name' => 'types',
        'url' => 'https://github.com/acme/types.git',
        'clone_path' => $this->repo->root,
        'head_sha' => $this->repo->head(),
    ]);

    $this->snapshot = Snapshot::create([
        'repository_id' => $this->repository->id,
        'commit_sha' => $this->repo->head(),
        'framework_version' => 10,
        'kind' => 'version_boundary',
    ]);

    // 10 observations: the classifier said unit for the first five, feature for the rest.
    // The one real file backs the source excerpt; line ranges point at its method.
    foreach (range(0, 9) as $i) {
        TestObservation::create([
            'snapshot_id' => $this->snapshot->id,
            'repository_id' => $this->repository->id,
            'file_path' => 'tests/Unit/SampledTest.php',
            'identifier' => "test_sampled_{$i}",
            'front_end' => 'phpunit',
            'test_type' => $i < 5 ? 'unit' : 'feature',
            'start_line' => 7,
            'end_line' => 10,
        ]);
    }

    $this->export = base_path('storage/framework/testing/type_sample.csv');
    File::ensureDirectoryExists(dirname($this->export));
});

afterEach(function () {
    /** @var TestCase $this */
    $this->repo->destroy();
    File::delete($this->export);
    File::delete(preg_replace('/\.csv$/', '', $this->export).'_labelled.csv');
    File::delete(preg_replace('/\.csv$/', '', $this->export).'_labelled_confusion.csv');
});

it('draws a reproducible seeded sample that leaks no classifier output', function () {
    /** @var TestCase $this */
    $this->artisan('analyse:sample-types', ['--n' => 4, '--seed' => '20260822', '--export' => $this->export])
        ->assertSuccessful();

    $rows = typeCsvRows($this->export);
    // Blind to classifier output: no test_type or test_type_rule column, blank human_label.
    expect($rows[0])->toBe(['id', 'repository', 'file_path', 'identifier', 'source_excerpt', 'human_label']);

    $firstDraw = array_column(array_slice($rows, 1), 0);
    expect($firstDraw)->toHaveCount(4);

    expect($rows[1][1])->toBe('acme/types')
        ->and($rows[1][4])->toContain('assertTrue') // the excerpt really is source, sliced by line range
        ->and($rows[1][5])->toBe('');

    // Same seed => the identical sample.
    $this->artisan('analyse:sample-types', ['--n' => 4, '--seed' => '20260822', '--export' => $this->export])
        ->assertSuccessful();
    expect(array_column(array_slice(typeCsvRows($this->export), 1), 0))->toBe($firstDraw);
});

it('requires the seed and export path, and refuses an empty dataset', function () {
    /** @var TestCase $this */
    $this->artisan('analyse:sample-types', ['--export' => $this->export])->assertFailed();
    $this->artisan('analyse:sample-types', ['--seed' => '1'])->assertFailed();

    TestObservation::query()->delete();
    $this->artisan('analyse:sample-types', ['--n' => 4, '--seed' => '1', '--export' => $this->export])
        ->assertFailed();
});

it('scores the classifier against hand labels with a hand-computed kappa', function () {
    /** @var TestCase $this */
    // Classifier said unit for #0-4, feature for #5-9. The human relabels #4 integration
    // and #9 unit: p_o = 8/10. Marginals — classifier unit .5 / feature .5; human unit .5
    // / feature .4 / integration .1 => p_e = .5·.5 + .5·.4 = 0.45.
    // kappa = (0.8 − 0.45)/0.55 = 7/11 = 0.6364 (substantial).
    $labelled = preg_replace('/\.csv$/', '', $this->export).'_labelled.csv';
    $handle = fopen($labelled, 'w');
    fputcsv($handle, ['id', 'repository', 'file_path', 'identifier', 'source_excerpt', 'human_label']);
    foreach (TestObservation::query()->orderBy('id')->get()->values() as $i => $observation) {
        $human = match (true) {
            $i === 4 => 'integration',
            $i === 9 => 'unit',
            default => $observation->test_type,
        };
        fputcsv($handle, [$observation->id, 'acme/types', $observation->file_path, $observation->identifier, '', $human]);
    }
    fclose($handle);

    test()->withoutMockingConsoleOutput();
    $exit = test()->artisan('analyse:score-types', ['path' => $labelled]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain("Cohen's kappa = 0.6364 (substantial, Landis & Koch) over 10 labelled observations")
        ->and($output)->toContain('classified feature ×1'); // the human-unit-row miss (#9)

    // Confusion matrix export: human rows × classifier columns over unit/feature/integration/unknown.
    $confusion = typeCsvRows(preg_replace('/\.csv$/', '', $labelled).'_confusion.csv');
    expect($confusion[0])->toBe(['human \ classifier', 'unit', 'feature', 'integration', 'unknown'])
        ->and($confusion[1])->toBe(['unit', '4', '1', '0', '0'])          // #9: human unit, classified feature
        ->and($confusion[2])->toBe(['feature', '0', '4', '0', '0'])
        ->and($confusion[3])->toBe(['integration', '1', '0', '0', '0']);  // #4: human integration, classified unit
});

it('rejects unknown labels, missing ids, and a CSV without the required columns', function () {
    /** @var TestCase $this */
    $labelled = preg_replace('/\.csv$/', '', $this->export).'_labelled.csv';

    File::put($labelled, "id,human_label\n1,banana\n");
    $this->artisan('analyse:score-types', ['path' => $labelled])->assertFailed();

    File::put($labelled, "id,human_label\n999999,unit\n");
    $this->artisan('analyse:score-types', ['path' => $labelled])->assertFailed();

    File::put($labelled, "something,else\n1,2\n");
    $this->artisan('analyse:score-types', ['path' => $labelled])->assertFailed();

    $this->artisan('analyse:score-types', ['path' => '/nonexistent.csv'])->assertFailed();
});
