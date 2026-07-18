<?php

declare(strict_types=1);

namespace App\Analysis\Extraction;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;

/**
 * Assertion = any call whose name matches /^(assert|expect)/i.
 *
 * This single rule covers all three idioms uniformly:
 *   - PHPUnit  $this->assertX(...)        (MethodCall, name asserts)
 *   - PHPUnit  $this->expectException(...) (MethodCall, name expects)
 *   - Pest     expect($x)->toBe(...)        (FuncCall expect(); one call = one assertion,
 *                                            chained matchers are part of the same assertion)
 *
 * State this operational definition verbatim in the methodology.
 */
final class AssertionCounter
{
    private const PATTERN = '/^(assert|expect)/i';

    /** @param Node[] $body statements of a test method / closure */
    public function count(array $body): int
    {
        $finder = new NodeFinder;

        $calls = $finder->find($body, static function (Node $n): bool {
            if (! ($n instanceof MethodCall
                || $n instanceof NullsafeMethodCall
                || $n instanceof StaticCall
                || $n instanceof FuncCall)) {
                return false;
            }
            $name = CallName::of($n);

            return $name !== null && preg_match(self::PATTERN, $name) === 1;
        });

        return count($calls);
    }
}
