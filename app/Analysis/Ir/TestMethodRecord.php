<?php

namespace App\Analysis\Ir;

use App\Analysis\Ir\Enums\FrontEndKind;
use App\Analysis\Ir\Enums\TestType;

/**
 * The canonical, front-end-agnostic representation of a single test.
 *
 * Both the PHPUnit (class/method) and Pest (closure) front-ends normalise into this shape.
 * Everything downstream consumes TestMethodRecord and never touches a raw AST again.
 */
final class TestMethodRecord
{
    /** @param list<MockRecord> $mocks */
    public function __construct(
        public string $identifier,          // method name, or Pest description string
        public FrontEndKind $frontEnd,
        public TestType $type = TestType::Unknown,
        public ?string $typeRule = null,    // which classifier rule fired (auditability)
        public int $assertionCount = 0,
        public array $mocks = [],
        public int $sizeStatements = 0,
        public int $sizeLoc = 0,
        public bool $usesRefreshDatabase = false,
        public bool $hasHttpCall = false,
        public bool $hasDbInteraction = false,
        /** @var array<string,int> free-form setup signals, e.g. ['factory_create' => 1] */
        public array $setupSignals = [],
        // Instrument B — backfilled by the blame pass, null at extraction time:
        public ?string $introducedCommitSha = null,
        public ?string $introducedAuthorDate = null, // ISO-8601
    ) {}

    public function mockBreadth(): int
    {
        return count($this->mocks);
    }

    public function maxMockChainDepth(): int
    {
        return array_reduce(
            $this->mocks,
            static fn (int $carry, MockRecord $m): int => max($carry, $m->chainDepth),
            0,
        );
    }

    /** @return list<string> distinct mock-kind values present */
    public function mockKinds(): array
    {
        return array_values(array_unique(array_map(
            static fn (MockRecord $m): string => $m->kind->value,
            $this->mocks,
        )));
    }
}
