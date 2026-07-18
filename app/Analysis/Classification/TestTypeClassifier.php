<?php

namespace App\Analysis\Classification;

use App\Analysis\Extraction\CallName;
use App\Analysis\Ir\Enums\TestType;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;

/**
 * Rule-based test-type classification on real signals, never directory names.
 *
 * Returns [TestType, ruleTag]. The ruleTag makes every decision auditable — the full rule
 * table belongs in the appendix. Rules are ordered; the first match wins.
 *
 *  R1  HTTP call present ($this->get/post/getJson/...)                 -> Feature
 *  R2  DB interaction (RefreshDatabase trait, ::factory(), assertDatabase*) -> Integration
 *  R3  Extends a framework/app TestCase but no HTTP/DB                  -> Unit
 *  R4  Extends plain PHPUnit\Framework\TestCase, no HTTP/DB            -> Unit
 *  R5  none of the above                                               -> Unknown
 */
final class TestTypeClassifier
{
    private const HTTP_METHODS = [
        'get', 'getJson', 'post', 'postJson', 'put', 'putJson', 'patch', 'patchJson',
        'delete', 'deleteJson', 'options', 'head', 'call', 'json',
    ];
    private const DB_ASSERTIONS = ['assertDatabaseHas', 'assertDatabaseMissing', 'assertDatabaseCount'];
    private const DB_TRAITS = ['RefreshDatabase', 'DatabaseTransactions', 'DatabaseMigrations'];

    /**
     * @param Node[] $body   test method / closure statements
     * @param list<string> $traits file/class-level trait simple-names
     * @param string|null $baseClass simple name of the extended base class
     * @return array{0: TestType, 1: string}
     */
    public function classify(array $body, array $traits, ?string $baseClass): array
    {
        $finder = new NodeFinder();

        $hasHttp = (bool) $finder->findFirst($body, function (Node $n): bool {
            return $n instanceof MethodCall
                && $this->isOnThis($n)
                && in_array(CallName::of($n), self::HTTP_METHODS, true);
        });
        if ($hasHttp) {
            return [TestType::Feature, 'R1_http_call'];
        }

        $usesDbTrait = (bool) array_intersect($traits, self::DB_TRAITS);
        $hasFactory = (bool) $finder->findFirst($body, static function (Node $n): bool {
            return $n instanceof StaticCall && CallName::of($n) === 'factory';
        });
        $hasDbAssert = (bool) $finder->findFirst($body, function (Node $n): bool {
            return $n instanceof MethodCall && in_array(CallName::of($n), self::DB_ASSERTIONS, true);
        });
        if ($usesDbTrait || $hasFactory || $hasDbAssert) {
            return [TestType::Integration, 'R2_db_interaction'];
        }

        if ($baseClass !== null && $baseClass !== 'TestCase') {
            return [TestType::Unit, 'R3_app_testcase_no_io'];
        }
        if ($baseClass === 'TestCase') {
            return [TestType::Unit, 'R4_phpunit_testcase_no_io'];
        }

        return [TestType::Unknown, 'R5_unclassified'];
    }

    private function isOnThis(MethodCall $mc): bool
    {
        return $mc->var instanceof Node\Expr\Variable && $mc->var->name === 'this';
    }
}
