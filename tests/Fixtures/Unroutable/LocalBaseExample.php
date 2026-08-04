<?php

/**
 * Negative guard: a project-local parent (BaseTest) is followed zero hops, so this file is
 * declined even though BaseTest may itself extend a TestCase somewhere else in the
 * repository. The unroutable table records it; transitive resolution is a separate,
 * not-yet-justified piece of work.
 * Hand-computed: routes to neither front end; detected base class BaseTest (raw name).
 */
class LocalBaseStyleTest extends BaseTest
{
    public function test_something(): void
    {
        $this->assertTrue(true);
    }
}
