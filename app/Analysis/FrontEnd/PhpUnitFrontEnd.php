<?php

declare(strict_types=1);

namespace App\Analysis\FrontEnd;

use App\Analysis\Ir\Enums\FrontEndKind;
use App\Analysis\Ir\TestFileRecord;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;

/**
 * Class-based front-end. Finds classes extending a *TestCase, then the test methods within
 * (name starts with "test", OR a #[Test] attribute, OR an @test docblock tag), and walks
 * each method body through the shared extractors.
 */
final class PhpUnitFrontEnd extends AbstractFrontEnd
{
    protected function kind(): FrontEndKind
    {
        return FrontEndKind::PhpUnit;
    }

    public function handles(string $source): bool
    {
        // Owns files that declare a class extending something ending in TestCase, and that
        // are NOT Pest closure files (no top-level it()/test() — Pest front-end owns those).
        return preg_match('/class\s+\w+\s+extends\s+\w*TestCase/', $source) === 1;
    }

    public function parse(string $path, string $source): ?TestFileRecord
    {
        $ast = $this->parser->parse($source);
        if ($ast === null) {
            return null;
        }

        $finder = new NodeFinder;
        /** @var Class_|null $class */
        $class = $finder->findFirst($ast, static fn (Node $n): bool => $n instanceof Class_ && $n->extends !== null);
        if ($class === null) {
            return null;
        }

        $baseClass = $class->extends?->getLast();
        $traits = $this->traitNames($class);

        $methods = [];
        foreach ($finder->findInstanceOf($class, ClassMethod::class) as $method) {
            if (! $this->isTestMethod($method)) {
                continue;
            }
            $methods[] = $this->buildMethod(
                identifier: $method->name->toString(),
                body: $method->stmts ?? [],
                ownerNode: $method,
                traits: $traits,
                baseClass: $baseClass,
            );
        }

        return new TestFileRecord($path, $this->kind(), $baseClass, $traits, $methods);
    }

    private function isTestMethod(ClassMethod $method): bool
    {
        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Test') {
                    return true;
                }
            }
        }
        $doc = $method->getDocComment()?->getText() ?? '';

        return str_contains($doc, '@test');
    }

    /** @return list<string> */
    private function traitNames(Class_ $class): array
    {
        $names = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $t) {
                    $names[] = $t->getLast();
                }
            }
        }

        return $names;
    }
}
