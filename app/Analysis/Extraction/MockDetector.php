<?php

namespace App\Analysis\Extraction;

use App\Analysis\Ir\Enums\MockKind;
use App\Analysis\Ir\MockRecord;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;

/**
 * Detects mocked collaborators inside one test, classifies each by kind, and measures the
 * fluent-chain depth rooted at the mock-creating call.
 *
 *   $this->mock(Gateway::class)      // 1 collaborator, kind=container
 *        ->shouldReceive('charge')   // chain hop 2
 *        ->once()                    // chain hop 3
 *        ->andReturn($receipt);      // chain hop 4  => chainDepth = 4
 *
 * Facade fakes (Http::fake(), Queue::fake(), ...) are a distinct kind and are counted as a
 * mock of breadth 1 with chainDepth 1.
 */
final class MockDetector
{
    private const CONTAINER_METHODS = ['mock', 'partialMock', 'spy', 'instance'];
    private const PHPUNIT_METHODS = ['createMock', 'createStub', 'createPartialMock', 'getMockBuilder'];
    private const FACADE_CLASSES = [
        'Http', 'Queue', 'Event', 'Storage', 'Mail', 'Bus', 'Notification', 'Cache', 'Log',
    ];

    /** @param Node[] $body */
    public function detect(array $body): array
    {
        $finder = new NodeFinder();

        // 1. Index every MethodCall that is an inner receiver of another MethodCall.
        //    A "chain root" is any MethodCall not appearing as another's ->var.
        $allMethodCalls = $finder->findInstanceOf($body, MethodCall::class);
        $innerIds = [];
        foreach ($allMethodCalls as $mc) {
            if ($mc->var instanceof MethodCall) {
                $innerIds[spl_object_id($mc->var)] = true;
            }
        }

        $mocks = [];

        // 2. Container-binding + Mockery + PHPUnit-native creators, with chain depth.
        foreach ($allMethodCalls as $mc) {
            $name = CallName::of($mc);
            if ($name === null) {
                continue;
            }

            $kind = null;
            $target = $this->firstClassConstArg($mc);

            if ($this->isOnThis($mc) && in_array($name, self::CONTAINER_METHODS, true)) {
                $kind = MockKind::Container;
            } elseif ($this->isOnThis($mc) && in_array($name, self::PHPUNIT_METHODS, true)) {
                $kind = MockKind::PhpUnitNative;
            }

            if ($kind !== null) {
                $mocks[] = new MockRecord($kind, $target, $this->chainDepthFrom($mc, $innerIds, $allMethodCalls));
            }
        }

        // 3. Mockery::mock(...) and facade fakes are static calls.
        foreach ($finder->findInstanceOf($body, StaticCall::class) as $sc) {
            $name = CallName::of($sc);
            $class = CallName::staticClass($sc);
            if ($name === null || $class === null) {
                continue;
            }

            if ($class === 'Mockery' && in_array($name, ['mock', 'spy', 'namedMock'], true)) {
                $mocks[] = new MockRecord(MockKind::Mockery, $this->firstClassConstArg($sc), 1);
            } elseif ($name === 'fake' && in_array($class, self::FACADE_CLASSES, true)) {
                $mocks[] = new MockRecord(MockKind::FacadeFake, $class, 1);
            }
        }

        return $mocks;
    }

    private function isOnThis(MethodCall $mc): bool
    {
        return $mc->var instanceof Node\Expr\Variable && $mc->var->name === 'this';
    }

    /** Count MethodCall hops in the chain whose innermost receiver is $root. */
    private function chainDepthFrom(MethodCall $root, array $innerIds, array $all): int
    {
        // Walk outward to a fixpoint: repeatedly find the MethodCall that wraps the current
        // node (candidate->var === current) and step up, counting hops. Order-independent.
        $depth = 1;
        $currentId = spl_object_id($root);
        $advanced = true;
        while ($advanced) {
            $advanced = false;
            foreach ($all as $candidate) {
                if ($candidate->var instanceof MethodCall && spl_object_id($candidate->var) === $currentId) {
                    $depth++;
                    $currentId = spl_object_id($candidate);
                    $advanced = true;
                    break;
                }
            }
        }

        return $depth;
    }

    private function firstClassConstArg(MethodCall|StaticCall $call): ?string
    {
        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg
                && $arg->value instanceof Node\Expr\ClassConstFetch
                && $arg->value->class instanceof Node\Name) {
                return $arg->value->class->getLast();
            }
        }

        return null;
    }
}
