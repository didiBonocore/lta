<?php

declare(strict_types=1);

use App\Analysis\FrontEnd\PestFrontEnd;
use App\Analysis\FrontEnd\PhpUnitFrontEnd;
use App\Analysis\Ir\Enums\TestType;
use App\Analysis\Ir\TestFileRecord;

/**
 * M0 — the extraction core proven against known-answer fixtures, and the design invariant
 * (PHPUnit and Pest normalise to identical IR) proven directly. This is the fixture pass
 * that the Methodology/validation section quotes. Runs as pure static analysis: the fixture
 * files are read as strings and parsed; they are never executed, so their references to
 * absent app classes are irrelevant.
 */
function parseFixture(string $relative): TestFileRecord
{
    $path = __DIR__.'/../Fixtures/'.$relative;
    $source = file_get_contents($path);
    $frontEnd = str_starts_with($relative, 'PhpUnit/') ? new PhpUnitFrontEnd : new PestFrontEnd;

    $file = $frontEnd->parse($path, $source);
    expect($file)->not->toBeNull();

    return $file;
}

it('extracts the PHPUnit feature login test to its hand-computed values', function () {
    $m = parseFixture('PhpUnit/FeatureLoginExample.php')->methods[0];

    expect($m->type)->toBe(TestType::Feature)
        ->and($m->testAssertionCount)->toBe(2)
        ->and($m->mockAssertionCount)->toBe(0)
        ->and($m->totalAssertionCount)->toBe(2)
        ->and($m->mockAssertionRatio)->toBe(0.0)
        ->and($m->mockBreadth())->toBe(0)
        ->and($m->usesRefreshDatabase)->toBeTrue()
        ->and($m->sizeStatements)->toBe(3);
})->group('fixtures');

it('extracts the PHPUnit unit gateway test to its hand-computed values', function () {
    $m = parseFixture('PhpUnit/UnitGatewayExample.php')->methods[0];

    expect($m->type)->toBe(TestType::Unit)
        ->and($m->testAssertionCount)->toBe(1)
        ->and($m->mockAssertionCount)->toBe(1)
        ->and($m->totalAssertionCount)->toBe(2)
        ->and($m->mockAssertionRatio)->toBe(0.5)
        ->and($m->mockBreadth())->toBe(1)
        ->and($m->maxMockChainDepth())->toBe(4)
        ->and($m->mockKinds())->toBe(['container'])
        ->and($m->sizeStatements)->toBe(2);
})->group('fixtures');

it('extracts PHPUnit mock assertion fixture with stubs vs mock verifications', function () {
    $m = parseFixture('PhpUnit/MockAssertionExample.php')->methods[0];

    expect($m->testAssertionCount)->toBe(1)
        ->and($m->mockAssertionCount)->toBe(5)
        ->and($m->totalAssertionCount)->toBe(6)
        ->and($m->mockAssertionRatio)->toEqualWithDelta(5 / 6, 1e-6);
})->group('fixtures');

it('extracts Pest mock assertion fixture with stubs vs mock verifications', function () {
    $m = parseFixture('Pest/MockAssertionExample.php')->methods[0];

    expect($m->testAssertionCount)->toBe(1)
        ->and($m->mockAssertionCount)->toBe(5)
        ->and($m->totalAssertionCount)->toBe(6)
        ->and($m->mockAssertionRatio)->toEqualWithDelta(5 / 6, 1e-6);
})->group('fixtures');

it('counts mock breadth both including and excluding facade fakes, identically across twins', function () {
    // One facade fake (Queue) + one container mock (PaymentGateway):
    // mockBreadth = 2, mockBreadthExcludingFacades = 1.
    $php = parseFixture('PhpUnit/FacadeFakeBreadthExample.php')->methods[0];
    $pest = parseFixture('Pest/FacadeFakeBreadthExample.php')->methods[0];

    expect($php->mockBreadth())->toBe(2)
        ->and($php->mockBreadthExcludingFacades())->toBe(1)
        ->and($pest->mockBreadth())->toBe($php->mockBreadth())
        ->and($pest->mockBreadthExcludingFacades())->toBe($php->mockBreadthExcludingFacades());
})->group('fixtures');

it('normalises PHPUnit and Pest feature twins to identical IR', function () {
    $php = parseFixture('PhpUnit/FeatureLoginExample.php')->methods[0];
    $pest = parseFixture('Pest/FeatureLoginExample.php')->methods[0];

    expect($pest->type)->toBe($php->type)
        ->and($pest->testAssertionCount)->toBe($php->testAssertionCount)
        ->and($pest->mockAssertionCount)->toBe($php->mockAssertionCount)
        ->and($pest->totalAssertionCount)->toBe($php->totalAssertionCount)
        ->and($pest->mockAssertionRatio)->toBe($php->mockAssertionRatio)
        ->and($pest->mockBreadth())->toBe($php->mockBreadth())
        ->and($pest->usesRefreshDatabase)->toBe($php->usesRefreshDatabase)
        ->and($pest->sizeStatements)->toBe($php->sizeStatements);
})->group('fixtures');

it('normalises PHPUnit and Pest unit+mock twins to identical IR', function () {
    $php = parseFixture('PhpUnit/UnitGatewayExample.php')->methods[0];
    $pest = parseFixture('Pest/UnitGatewayExample.php')->methods[0];

    expect($pest->type)->toBe($php->type)->toBe(TestType::Unit)
        ->and($pest->testAssertionCount)->toBe($php->testAssertionCount)->toBe(1)
        ->and($pest->mockAssertionCount)->toBe($php->mockAssertionCount)->toBe(1)
        ->and($pest->totalAssertionCount)->toBe($php->totalAssertionCount)->toBe(2)
        ->and($pest->mockAssertionRatio)->toBe($php->mockAssertionRatio)->toBe(0.5)
        ->and($pest->mockBreadth())->toBe($php->mockBreadth())->toBe(1)
        ->and($pest->maxMockChainDepth())->toBe($php->maxMockChainDepth())->toBe(4);
})->group('fixtures');

it('normalises one Pest expectation chain and three PHPUnit assertions to identical counts', function () {
    $php = parseFixture('PhpUnit/ChainedExpectationExample.php')->methods[0];
    $pest = parseFixture('Pest/ChainedExpectationExample.php')->methods[0];

    expect($pest->testAssertionCount)->toBe($php->testAssertionCount)->toBe(3)
        ->and($pest->mockAssertionCount)->toBe($php->mockAssertionCount)->toBe(0)
        ->and($pest->totalAssertionCount)->toBe($php->totalAssertionCount)->toBe(3)
        ->and($pest->mockAssertionRatio)->toBe($php->mockAssertionRatio)->toBe(0.0);
})->group('fixtures');

it('counts negation, conjunction and higher-order modifiers to their hand-computed values', function () {
    $m = parseFixture('Pest/NegationModifierExample.php')->methods[0];

    expect($m->testAssertionCount)->toBe(4)
        ->and($m->mockAssertionCount)->toBe(0)
        ->and($m->totalAssertionCount)->toBe(4)
        ->and($m->mockAssertionRatio)->toBe(0.0);
})->group('fixtures');

it('extracts a Pest file with an inline double to two records, the double contributing none', function () {
    $file = parseFixture('Pest/InlineDoubleExample.php');

    expect($file->methods)->toHaveCount(2)
        ->and($file->methods[0]->identifier)->toBe('drives with the fake')
        ->and($file->methods[0]->testAssertionCount)->toBe(1)
        ->and($file->methods[1]->identifier)->toBe('reports readiness')
        ->and($file->methods[1]->testAssertionCount)->toBe(1)
        ->and($file->methods[0]->mockAssertionCount)->toBe(0)
        ->and($file->methods[1]->mockAssertionCount)->toBe(0);
})->group('fixtures');

it('produces identical records for identical bodies under qualified, fully-qualified and aliased parents', function () {
    $qualified = parseFixture('PhpUnit/QualifiedParentExample.php')->methods[0];
    $fullyQualified = parseFixture('PhpUnit/FullyQualifiedParentExample.php')->methods[0];
    $aliased = parseFixture('PhpUnit/AliasedParentExample.php')->methods[0];

    foreach ([$fullyQualified, $aliased] as $twin) {
        expect($twin->identifier)->toBe($qualified->identifier)
            ->and($twin->testAssertionCount)->toBe($qualified->testAssertionCount)->toBe(2)
            ->and($twin->mockAssertionCount)->toBe($qualified->mockAssertionCount)->toBe(0)
            ->and($twin->type)->toBe($qualified->type);
    }
})->group('fixtures');

it('counts closure-form grouping expectations to their hand-computed values', function () {
    $m = parseFixture('Pest/HigherOrderExpectationExample.php')->methods[0];

    expect($m->testAssertionCount)->toBe(5)
        ->and($m->mockAssertionCount)->toBe(0)
        ->and($m->totalAssertionCount)->toBe(5)
        ->and($m->mockAssertionRatio)->toBe(0.0);
})->group('fixtures');

it('does not count each() or when() on chains not rooted at expect()', function () {
    $m = parseFixture('Pest/HigherOrderExpectationExample.php')->methods[1];

    expect($m->testAssertionCount)->toBe(0)
        ->and($m->mockAssertionCount)->toBe(0)
        ->and($m->totalAssertionCount)->toBe(0)
        ->and($m->mockAssertionRatio)->toBe(0.0);
})->group('fixtures');

it('does not count toArray() calls whose chain is not rooted at expect()', function () {
    $m = parseFixture('Pest/ToArrayGuardExample.php')->methods[0];

    expect($m->testAssertionCount)->toBe(1)
        ->and($m->mockAssertionCount)->toBe(0)
        ->and($m->totalAssertionCount)->toBe(1)
        ->and($m->mockAssertionRatio)->toBe(0.0);
})->group('fixtures');
