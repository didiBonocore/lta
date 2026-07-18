<?php

declare(strict_types=1);

namespace App\Analysis\Ir;

use App\Analysis\Ir\Enums\MockKind;

/**
 * One mocked collaborator inside a single test method.
 */
final readonly class MockRecord
{
    public function __construct(
        public MockKind $kind,
        public ?string $target,   // mocked class/dependency if statically recoverable
        public int $chainDepth,   // length of the fluent expectation chain rooted here
    ) {}
}
