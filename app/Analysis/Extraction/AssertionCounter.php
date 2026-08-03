<?php

declare(strict_types=1);

namespace App\Analysis\Extraction;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;

final class AssertionCountResult
{
    public function __construct(
        public int $testAssertionCount = 0,
        public int $mockAssertionCount = 0,
    ) {}

    public function total(): int
    {
        return $this->testAssertionCount + $this->mockAssertionCount;
    }

    public function ratio(): float
    {
        $total = $this->total();

        return $total > 0 ? $this->mockAssertionCount / (float) $total : 0.0;
    }
}

final class AssertionCounter
{
    private const COUNT_CONSTRAINTS = [
        'once', 'twice', 'times', 'atleast', 'atleastonce', 'atmost', 'never',
    ];

    /**
     * Higher-order expectation calls that group sub-expectations, typically in closures.
     * Each counts as exactly one terminator and its arguments are never descended into:
     * the number of inner expectations they evaluate is a runtime property of the subject,
     * so no determinate static count exists. Documented in Appendix C and in the construct
     * validity discussion.
     */
    private const GROUPING_EXPECTATIONS = ['sequence', 'each', 'when', 'unless'];

    private const DEFAULT_FACADE_MOCK_ASSERTIONS = [
        'Event' => ['assertDispatched', 'assertNotDispatched', 'assertNothingDispatched'],
        'Queue' => ['assertPushed', 'assertNotPushed', 'assertPushedOn'],
        'Mail' => ['assertSent', 'assertQueued', 'assertNotSent', 'assertNothingSent'],
        'Notification' => ['assertSentTo', 'assertNotSentTo', 'assertNothingSent'],
        'Bus' => ['assertDispatched', 'assertNotDispatched', 'assertChained', 'assertBatched'],
        'Http' => ['assertSent', 'assertNotSent', 'assertSentCount', 'assertNothingSent'],
        'Storage' => ['assertExists', 'assertMissing'],
        'Process' => ['assertRan', 'assertDidntRun'],
        'Sleep' => ['assertSlept', 'assertSleptTimes', 'assertSleptWith', 'assertSleptWithAny'],
    ];

    /** @param Node[] $body statements of a test method / closure */
    public function count(array $body): AssertionCountResult
    {
        $finder = new NodeFinder;

        /** @var list<MethodCall|NullsafeMethodCall|StaticCall|FuncCall> $allCalls */
        $allCalls = $finder->find($body, static fn (Node $n): bool => $n instanceof MethodCall
            || $n instanceof NullsafeMethodCall
            || $n instanceof StaticCall
            || $n instanceof FuncCall
        );

        $facadeConfig = config('analyser.facade_mock_assertions', self::DEFAULT_FACADE_MOCK_ASSERTIONS);
        if (! is_array($facadeConfig)) {
            $facadeConfig = self::DEFAULT_FACADE_MOCK_ASSERTIONS;
        }

        $testAssertions = 0;
        $mockAssertions = 0;

        foreach ($allCalls as $call) {
            $name = CallName::of($call);
            if ($name === null) {
                continue;
            }

            // 1. Facade fake interaction verification (Mock assertion)
            if ($this->isFacadeMockAssertion($call, $name, $facadeConfig)) {
                $mockAssertions++;

                continue;
            }

            // 2. Mockery interaction verification with count constraint / spy (Mock assertion)
            if ($this->isMockeryMockAssertion($call, $name, $allCalls)) {
                $mockAssertions++;

                continue;
            }

            // 3. PHPUnit mock expectation with count constraint (Mock assertion)
            if ($this->isPhpUnitMockAssertion($call, $name)) {
                $mockAssertions++;

                continue;
            }

            // 4. Test assertion (state/output verification)
            if ($this->isTestAssertion($call, $name)) {
                $testAssertions++;
            }
        }

        return new AssertionCountResult($testAssertions, $mockAssertions);
    }

    /** @param array<string, list<string>> $facadeConfig */
    private function isFacadeMockAssertion(Node $call, string $name, array $facadeConfig): bool
    {
        $class = null;
        if ($call instanceof StaticCall) {
            $class = CallName::staticClass($call);
        }

        if ($class !== null && isset($facadeConfig[$class])) {
            return in_array($name, $facadeConfig[$class], true);
        }

        foreach ($facadeConfig as $facadeClass => $methods) {
            if (in_array($name, $methods, true)) {
                if ($call instanceof StaticCall) {
                    return $class === null || $class === $facadeClass;
                }

                return true;
            }
        }

        return false;
    }

    /** @param list<Node> $allCalls */
    private function isMockeryMockAssertion(Node $call, string $name, array $allCalls): bool
    {
        if (! ($call instanceof MethodCall || $call instanceof NullsafeMethodCall)) {
            return false;
        }

        $lower = strtolower($name);

        if (in_array($lower, ['shouldnotreceive', 'shouldhavereceived', 'shouldnothavereceived'], true)) {
            return true;
        }

        if ($lower !== 'shouldreceive') {
            return false;
        }

        return $this->chainHasCountConstraint($call, $allCalls);
    }

    /** @param list<Node> $allCalls */
    private function chainHasCountConstraint(MethodCall|NullsafeMethodCall $root, array $allCalls): bool
    {
        $currentId = spl_object_id($root);

        while (true) {
            $foundParent = false;
            foreach ($allCalls as $candidate) {
                if (($candidate instanceof MethodCall || $candidate instanceof NullsafeMethodCall)
                    && spl_object_id($candidate->var) === $currentId) {

                    $parentName = CallName::of($candidate);
                    if ($parentName !== null && in_array(strtolower($parentName), self::COUNT_CONSTRAINTS, true)) {
                        return true;
                    }

                    $currentId = spl_object_id($candidate);
                    $foundParent = true;

                    break;
                }
            }

            if (! $foundParent) {
                break;
            }
        }

        return false;
    }

    private function isPhpUnitMockAssertion(Node $call, string $name): bool
    {
        if (! ($call instanceof MethodCall || $call instanceof NullsafeMethodCall)) {
            return false;
        }

        if (strtolower($name) !== 'expects') {
            return false;
        }

        if (count($call->args) === 0) {
            return false;
        }

        $arg = $call->args[0];
        if (! $arg instanceof Arg) {
            return false;
        }

        $argVal = $arg->value;
        if (! ($argVal instanceof MethodCall || $argVal instanceof NullsafeMethodCall || $argVal instanceof StaticCall || $argVal instanceof FuncCall)) {
            return false;
        }

        $argName = CallName::of($argVal);
        if ($argName === null) {
            return false;
        }

        $argLower = strtolower($argName);

        if ($argLower === 'any') {
            return false;
        }

        return in_array($argLower, self::COUNT_CONSTRAINTS, true)
            || str_starts_with($argLower, 'atleast')
            || str_starts_with($argLower, 'atmost')
            || str_starts_with($argLower, 'exactly')
            || str_starts_with($argLower, 'once')
            || str_starts_with($argLower, 'never');
    }

    private function isTestAssertion(Node $call, string $name): bool
    {
        if ($this->isPestExpectationTerminator($call, $name)) {
            return true;
        }

        return (bool) preg_match('/^assert/i', $name);
    }

    /**
     * A Pest expectation terminator: a method whose name matches /^to[A-Z]/ (toBe, toHaveKey,
     * toThrow, ...) or is a GROUPING_EXPECTATIONS call, sitting in a chain whose innermost
     * receiver is the global expect() function call. Both conditions are required — the
     * chain-root constraint is what excludes domain methods such as $model->toArray(), or a
     * Laravel collection's each()/when() on a non-expectation subject. The expect() call
     * itself is not counted; each terminator counts once, so expect($x)->toBe(1)->toBeInt()
     * yields 2, matching its two-call PHPUnit equivalent (paradigm-invariant counting).
     *
     * The walk follows ->var receivers inward through method hops (toBe, and, json) and
     * property hops (not, each) alike, so modifiers are traversed but never counted.
     *
     * Grouping expectations (sequence, each, when, unless) are deliberately counted as exactly
     * one terminator each and the closures passed to them are not descended into: expectations
     * inside them (fn ($e) => $e->toBe(1)) root at the closure parameter rather than at
     * expect(), so the chain-root test rejects them. This is a documented decision, traceable
     * to the dissertation's construct validity section.
     */
    private function isPestExpectationTerminator(Node $call, string $name): bool
    {
        if (! ($call instanceof MethodCall || $call instanceof NullsafeMethodCall)) {
            return false;
        }

        if (preg_match('/^to[A-Z]/', $name) !== 1
            && ! in_array(strtolower($name), self::GROUPING_EXPECTATIONS, true)) {
            return false;
        }

        $node = $call->var;
        while ($node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof PropertyFetch
            || $node instanceof NullsafePropertyFetch) {
            $node = $node->var;
        }

        return $node instanceof FuncCall && strtolower(CallName::of($node) ?? '') === 'expect';
    }
}
