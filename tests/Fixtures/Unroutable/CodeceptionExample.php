<?php

/**
 * Negative guard: the resolved parent's final name segment is Test, not TestCase, so this
 * must be declined by both front ends and recorded unroutable — the same rule that declined
 * it in the regex era.
 *
 * The parent must remain written fully qualified in the extends clause — do not let a
 * formatter hoist it into a use import.
 * Hand-computed: routes to neither front end; detected base class Codeception\TestCase\Test.
 */
class CodeceptionStyleTest extends \Codeception\TestCase\Test
{
    public function test_something(): void
    {
        $this->assertTrue(true);
    }
}
