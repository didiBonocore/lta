<?php

declare(strict_types=1);

use App\Analysis\FrontEnd\UnroutableClassifier;

/**
 * The classifier explains files every front end declined; it never influences routing. The
 * cases mirror what the pilot's Codeception eras actually contain: fully-qualified parents,
 * use-imported parents (plain and aliased), and support files with no class at all.
 */
it('detects the base class of an unroutable file', function (string $source, string $expected) {
    expect((new UnroutableClassifier)->baseClassOf($source))->toBe($expected);
})->with([
    'fully-qualified parent' => [
        '<?php class BaseTest extends \Codeception\TestCase\Test {}',
        'Codeception\TestCase\Test',
    ],
    'parent resolved through a use statement' => [
        '<?php use Codeception\Module; class Api extends Module {}',
        'Codeception\Module',
    ],
    'parent resolved through an aliased use statement' => [
        '<?php use Codeception\Actor as BaseActor; class UnitTester extends BaseActor {}',
        'Codeception\Actor',
    ],
    'qualified parent whose prefix is imported' => [
        '<?php use Codeception\TestCase; class BaseTest extends TestCase\Test {}',
        'Codeception\TestCase\Test',
    ],
    'namespaced file' => [
        '<?php namespace Tests\Unit; use Codeception\Module; class Api extends Module {}',
        'Codeception\Module',
    ],
    'unresolvable parent stays raw' => [
        '<?php class BaseTest extends SomethingLocal {}',
        'SomethingLocal',
    ],
    'class without a parent' => [
        '<?php class Helper {}',
        UnroutableClassifier::CLASS_WITHOUT_PARENT,
    ],
    'no class and no top-level test call' => [
        '<?php function helper() { return 1; }',
        UnroutableClassifier::NO_CLASS_NO_TEST_CALL,
    ],
    'unparseable source' => [
        '<?php class Broken extends {',
        UnroutableClassifier::UNPARSEABLE,
    ],
]);
