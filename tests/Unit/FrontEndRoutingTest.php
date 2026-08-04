<?php

declare(strict_types=1);

use App\Analysis\FrontEnd\FrontEndRouter;
use App\Analysis\FrontEnd\PestFrontEnd;
use App\Analysis\FrontEnd\PhpUnitFrontEnd;
use PhpParser\Error as ParseError;

/**
 * Known-answer table for the AST routing predicate — every row of the brief's case table.
 * Routing is decided on the parse tree with names resolved first, so aliases can neither
 * mask nor fake a TestCase parent, and $this->test() (a MethodCall) can never look like a
 * Pest call (a FuncCall).
 */
function routeSource(string $code): ?string
{
    $frontEnd = (new FrontEndRouter)->route($code);

    return $frontEnd === null ? null : $frontEnd::class;
}

function routeFixture(string $relative): ?string
{
    return routeSource((string) file_get_contents(__DIR__.'/../Fixtures/'.$relative));
}

it('routes by the AST predicate', function (string $code, ?string $expected) {
    expect(routeSource($code))->toBe($expected);
})->with([
    'top-level test() routes to Pest' => ['<?php test("x", fn () => null);', PestFrontEnd::class],
    'top-level it() routes to Pest' => ['<?php it("x", fn () => null);', PestFrontEnd::class],
    'unbraced namespace does not block Pest' => ['<?php namespace Foo; test("x", fn () => null);', PestFrontEnd::class],
    'call inside a file-scope closure routes to Pest' => ['<?php $register = function () { it("x", fn () => null); };', PestFrontEnd::class],
    'extends TestCase routes to PHPUnit' => ['<?php class FooTest extends TestCase {}', PhpUnitFrontEnd::class],
    'extends BaseTestCase routes to PHPUnit' => ['<?php class FooTest extends BaseTestCase {}', PhpUnitFrontEnd::class],
    'it() inside a class method is not a Pest call' => ['<?php class FooTest extends TestCase { public function test_x(): void { it("x", fn () => null); } }', PhpUnitFrontEnd::class],
    'aliasing Codeception Test AS TestCase does not fake a parent' => ['<?php use Codeception\TestCase\Test as TestCase; class FooTest extends TestCase {}', null],
    'class without a parent routes nowhere' => ['<?php class Helper {}', null],
    'beforeEach plus bare calls without it()/test() routes nowhere' => ['<?php beforeEach(function () { login(); }); getJson("api/v1/dashboard")->assertOk();', null],
    'anonymous class extending TestCase does not route' => ['<?php $double = new class extends TestCase {};', null],
])->group('fixtures');

it('routes the fixture files by the AST predicate', function (string $fixture, ?string $expected) {
    expect(routeFixture($fixture))->toBe($expected);
})->with([
    'Pest file with an inline double routes to Pest' => ['Pest/InlineDoubleExample.php', PestFrontEnd::class],
    'braced namespace routes to Pest' => ['Pest/BracedNamespaceExample.php', PestFrontEnd::class],
    'file-scope conditional routes to Pest' => ['Pest/ConditionalCallExample.php', PestFrontEnd::class],
    'qualified parent routes to PHPUnit' => ['PhpUnit/QualifiedParentExample.php', PhpUnitFrontEnd::class],
    'fully-qualified parent routes to PHPUnit' => ['PhpUnit/FullyQualifiedParentExample.php', PhpUnitFrontEnd::class],
    'aliased parent routes to PHPUnit' => ['PhpUnit/AliasedParentExample.php', PhpUnitFrontEnd::class],
    '$this->test() helper routes to PHPUnit, never Pest' => ['PhpUnit/MethodCallHelperExample.php', PhpUnitFrontEnd::class],
    'Codeception parent (final segment Test) declined' => ['Unroutable/CodeceptionExample.php', null],
    'project-local BaseTest parent declined (zero hops)' => ['Unroutable/LocalBaseExample.php', null],
])->group('fixtures');

it('propagates parse errors to the caller for parse-failure recording', function () {
    (new FrontEndRouter)->route('<?php class Broken extends {');
})->throws(ParseError::class);
