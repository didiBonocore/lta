<?php

declare(strict_types=1);

use App\Analysis\Extraction\AssertionCounter;
use App\Analysis\Extraction\AssertionCountResult;
use PhpParser\ParserFactory;

/**
 * Known-answer table for paradigm-invariant state-assertion counting: every expectation
 * form from the counting-rule case table, parsed as a raw statement list and fed straight
 * to AssertionCounter. Complements the fixture pass in ExtractionCorrectnessTest, which
 * proves the same rule end-to-end through the front-ends.
 */
function countBodyAssertions(string $code): AssertionCountResult
{
    $stmts = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code);
    expect($stmts)->not->toBeNull();

    return (new AssertionCounter)->count($stmts);
}

it('counts state assertions invariantly across expectation forms', function (string $code, int $expected) {
    $result = countBodyAssertions($code);

    expect($result->testAssertionCount)->toBe($expected)
        ->and($result->mockAssertionCount)->toBe(0);
})->with([
    'baseline single expectation' => ['expect($x)->toBeTrue();', 1],
    'chained expectation counts each terminator' => ['expect($x)->toBe(1)->toBeInt();', 2],
    'not is a modifier' => ['expect($x)->not->toBeEmpty();', 1],
    'and() is a modifier, not a terminator' => ['expect($x)->toBe(1)->and($y)->toBe(2);', 2],
    'higher-order each is a modifier' => ['expect($c)->each->toBeInt();', 1],
    'json() is a modifier' => ["expect(\$v)->json()->toHaveKey('a');", 1],
    'toThrow matches the terminator pattern' => ['expect($fn)->toThrow(RuntimeException::class);', 1],
    'toArray() inside the argument is not a terminator' => ['expect($m->toArray())->toBe([]);', 1],
    'bare toArray() statement is not counted' => ['$m->toArray();', 0],
    'expect() itself is not counted' => ['expect($x);', 0],
    'PHPUnit assertion inside a Pest closure' => ['$this->assertTrue($x);', 1],
    'chained response assertions keep counting per call' => ["\$this->get('/')->assertOk()->assertSee('x');", 2],
    'sequence() counts as one terminator, closures not descended' => ['expect([1, 2])->sequence(fn ($e) => $e->toBe(1), fn ($e) => $e->toBe(2));', 1],
    'closure-form each() counts as one terminator' => ['expect($c)->each(fn ($e) => $e->toBeInt());', 1],
    'when() counts as one terminator' => ['expect($x)->when($cond, fn ($e) => $e->toBeInt());', 1],
    'unless() counts as one terminator' => ['expect($x)->unless($cond, fn ($e) => $e->toBeInt());', 1],
    'grouping call plus a real terminator' => ['expect($c)->each(fn ($e) => $e->toBeInt())->toHaveCount(3);', 2],
    'collection each() without expect() root is not counted' => ['$collection->each(fn ($x) => $x->save());', 0],
    'conditionable when() without expect() root is not counted' => ['$this->when($c, fn () => null);', 0],
])->group('fixtures');

it('counts the property and closure forms of each() identically', function () {
    $property = countBodyAssertions('expect($c)->each->toBeInt();');
    $closure = countBodyAssertions('expect($c)->each(fn ($e) => $e->toBeInt());');

    expect($closure->testAssertionCount)->toBe($property->testAssertionCount)
        ->and($closure->mockAssertionCount)->toBe($property->mockAssertionCount);
})->group('fixtures');
