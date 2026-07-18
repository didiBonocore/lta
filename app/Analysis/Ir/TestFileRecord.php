<?php

namespace App\Analysis\Ir;

use App\Analysis\Ir\Enums\FrontEndKind;

/**
 * All tests discovered in one file, plus file-level context (base class, traits) that the
 * classifier needs. Produced by a FrontEnd.
 */
final class TestFileRecord
{
    /**
     * @param list<TestMethodRecord> $methods
     * @param list<string> $traits   simple names of traits used at file/class level
     */
    public function __construct(
        public string $path,
        public FrontEndKind $frontEnd,
        public ?string $baseClass,   // e.g. Tests\TestCase or PHPUnit\Framework\TestCase
        public array $traits = [],
        public array $methods = [],
    ) {}
}
