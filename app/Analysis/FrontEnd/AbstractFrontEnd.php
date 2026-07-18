<?php

declare(strict_types=1);

namespace App\Analysis\FrontEnd;

use App\Analysis\Classification\TestTypeClassifier;
use App\Analysis\Extraction\AssertionCounter;
use App\Analysis\Extraction\CallName;
use App\Analysis\Extraction\MockDetector;
use App\Analysis\Ir\Enums\FrontEndKind;
use App\Analysis\Ir\TestMethodRecord;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Shared machinery: parsing, and the single place where a statement body becomes a fully
 * populated TestMethodRecord. Both concrete front-ends call buildMethod(), which guarantees
 * a PHPUnit method and its Pest-closure equivalent yield identical IR.
 */
abstract class AbstractFrontEnd implements FrontEnd
{
    protected Parser $parser;

    public function __construct(
        protected AssertionCounter $assertions = new AssertionCounter,
        protected MockDetector $mocks = new MockDetector,
        protected TestTypeClassifier $classifier = new TestTypeClassifier,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    abstract protected function kind(): FrontEndKind;

    /**
     * @param  Node[]  $body  statements of the test
     * @param  list<string>  $traits
     */
    protected function buildMethod(
        string $identifier,
        array $body,
        Node $ownerNode,
        array $traits,
        ?string $baseClass,
    ): TestMethodRecord {
        [$type, $rule] = $this->classifier->classify($body, $traits, $baseClass);
        $mocks = $this->mocks->detect($body);

        $finder = new NodeFinder;
        $statements = $finder->find($body, static fn (Node $n): bool => $n instanceof Node\Stmt);

        return new TestMethodRecord(
            identifier: $identifier,
            frontEnd: $this->kind(),
            type: $type,
            typeRule: $rule,
            assertionCount: $this->assertions->count($body),
            mocks: $mocks,
            sizeStatements: count($statements),
            sizeLoc: max(1, ($ownerNode->getEndLine() - $ownerNode->getStartLine()) + 1),
            usesRefreshDatabase: in_array('RefreshDatabase', $traits, true),
            hasHttpCall: $type->value === 'feature',
            hasDbInteraction: in_array($type->value, ['feature', 'integration'], true),
            setupSignals: $this->setupSignals($body),
        );
    }

    /** @param Node[] $body @return array<string,int> */
    protected function setupSignals(array $body): array
    {
        $finder = new NodeFinder;
        $signals = [];
        foreach ($finder->find($body, static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall) as $call) {
            $name = CallName::of($call);
            if ($name === 'factory') {
                $signals['factory'] = ($signals['factory'] ?? 0) + 1;
            }
            if ($name === 'create' || $name === 'make') {
                $signals[$name] = ($signals[$name] ?? 0) + 1;
            }
        }

        return $signals;
    }
}
