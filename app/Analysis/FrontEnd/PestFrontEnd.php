<?php

declare(strict_types=1);

namespace App\Analysis\FrontEnd;

use App\Analysis\Ir\Enums\FrontEndKind;
use App\Analysis\Ir\TestFileRecord;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Closure-based front-end. Finds top-level it()/test() calls, extracts the description and
 * the test closure, resolves file-level uses(...) to recover the effective base traits, and
 * walks the closure body through the *same* shared extractors as the PHPUnit front-end.
 *
 * Because both front-ends terminate in buildMethod(), a Pest test and its PHPUnit twin
 * produce identical IR — the design invariant the fixtures assert.
 */
final class PestFrontEnd extends AbstractFrontEnd
{
    protected function kind(): FrontEndKind
    {
        return FrontEndKind::Pest;
    }

    public function handles(string $source): bool
    {
        // Owns files with top-level it(...) / test(...) and no class declaration.
        return preg_match('/^\s*(it|test)\s*\(/m', $source) === 1
            && preg_match('/^\s*class\s+/m', $source) !== 1;
    }

    public function parse(string $path, string $source): ?TestFileRecord
    {
        $ast = $this->parser->parse($source);
        if ($ast === null) {
            return null;
        }

        $traits = $this->fileLevelTraits($ast);
        // In Pest, the effective base class is the app TestCase bound in Pest.php; we record
        // it as "Pest\TestCase" unless a uses(SomethingTestCase::class) overrides it.
        $baseClass = 'PestTestCase';

        $finder = new NodeFinder;
        $methods = [];
        foreach ($finder->findInstanceOf($ast, FuncCall::class) as $call) {
            $name = $call->name instanceof Node\Name ? $call->name->getLast() : null;
            if (! in_array($name, ['it', 'test'], true)) {
                continue;
            }

            [$description, $closure] = $this->descriptionAndClosure($call);
            if ($closure === null) {
                continue;
            }

            $methods[] = $this->buildMethod(
                identifier: $description ?? '(anonymous)',
                body: $closure->stmts ?? [],
                ownerNode: $closure,
                traits: $traits,
                baseClass: $baseClass,
            );
        }

        return new TestFileRecord($path, $this->kind(), $baseClass, $traits, $methods);
    }

    /** Resolve uses(A::class, B::class) at file level into trait/base simple-names. */
    private function fileLevelTraits(array $ast): array
    {
        $finder = new NodeFinder;
        $names = [];
        foreach ($finder->findInstanceOf($ast, FuncCall::class) as $call) {
            $fname = $call->name instanceof Node\Name ? $call->name->getLast() : null;
            if ($fname !== 'uses') {
                continue;
            }
            foreach ($call->args as $arg) {
                if ($arg->value instanceof Node\Expr\ClassConstFetch
                    && $arg->value->class instanceof Node\Name) {
                    $names[] = $arg->value->class->getLast();
                }
            }
        }

        return $names;
    }

    /** @return array{0: ?string, 1: Closure|ArrowFunction|null} */
    private function descriptionAndClosure(FuncCall $call): array
    {
        $description = null;
        $closure = null;
        foreach ($call->args as $arg) {
            if ($arg->value instanceof String_ && $description === null) {
                $description = $arg->value->value;
            } elseif ($arg->value instanceof Closure || $arg->value instanceof ArrowFunction) {
                $closure = $arg->value;
            }
        }

        return [$description, $closure];
    }
}
