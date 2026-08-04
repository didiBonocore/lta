<?php

declare(strict_types=1);

namespace App\Analysis\FrontEnd;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Routes a test file to the owning front end from its parse tree, replacing the former
 * regex-over-source predicates. Names are resolved (use statements and aliases) with
 * php-parser's NameResolver BEFORE the decision, so an alias never masks or fakes a parent
 * class. Pest keeps first refusal.
 *
 * Pest — the file contains at least one it()/test() FuncCall with no class-like ancestor
 * (not lexically inside a class, interface, trait or enum body). Defining it by the absence
 * of a class ancestor rather than by position in the top-level statement list handles braced
 * and unbraced namespaces, calls inside file-scope if blocks, and calls inside file-scope
 * closures without special cases — and means a class declared elsewhere in the file (a test
 * double) never vetoes Pest routing. $this->test(...) is a MethodCall, not a FuncCall, so a
 * PHPUnit helper named test() can never trigger Pest routing.
 *
 * PHPUnit — otherwise, the file declares a named class whose RESOLVED parent has a final
 * name segment matching /TestCase$/. Deliberately zero hops: a project-local parent such as
 * BaseTest stays declined even if it transitively extends a TestCase — the unroutable table
 * exists to tell us whether transitive resolution is worth building.
 *
 * Neither — null; the caller records the file as unroutable with its detected base class.
 */
final class FrontEndRouter
{
    private Parser $parser;

    public function __construct(
        private PestFrontEnd $pest = new PestFrontEnd,
        private PhpUnitFrontEnd $phpUnit = new PhpUnitFrontEnd,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * The owning front end for one file's source together with the parse tree the decision
     * was made on, or null when no front end owns it. The file is parsed exactly once:
     * NameResolver runs in attribute-only mode (replaceNodes: false), so the tree handed to
     * the front end is structurally identical to a fresh parse and no observation can
     * differ from the parse-twice era.
     *
     * @throws Error the caller records parse failures
     */
    public function route(string $source): ?RoutedFile
    {
        $ast = $this->parser->parse($source);
        if ($ast === null) {
            return null;
        }

        $traverser = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]));
        $ast = $traverser->traverse($ast);

        if ($this->hasFileScopePestCall($ast)) {
            return new RoutedFile($this->pest, $ast);
        }

        return $this->declaresTestCaseClass($ast) ? new RoutedFile($this->phpUnit, $ast) : null;
    }

    /** The resolved form of a name where NameResolver could resolve it; the name as written otherwise. */
    private function resolved(Name $name): Name
    {
        $resolvedName = $name->getAttribute('resolvedName');

        return $resolvedName instanceof Name ? $resolvedName : $name;
    }

    /**
     * True when an it()/test() FuncCall exists outside every class-like body. Function names
     * are case-insensitive and resolve through the global fallback in namespaced files, so
     * the comparison is on the last name segment, lower-cased — which also matches a
     * hypothetical namespaced Foo\test(); accepted as negligible.
     *
     * @param  Node[]  $ast
     */
    private function hasFileScopePestCall(array $ast): bool
    {
        $router = $this;
        $visitor = new class($router) extends NodeVisitorAbstract
        {
            public bool $found = false;

            public function __construct(private FrontEndRouter $router) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof FuncCall
                    && $node->name instanceof Name
                    && in_array(strtolower($this->router->resolvedLast($node->name)), ['it', 'test'], true)) {
                    $this->found = true;

                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    /**
     * True when any named class in the file extends a parent whose resolved final name
     * segment ends in TestCase. Anonymous classes are excluded, preserving the previous
     * named-class behaviour; \Codeception\TestCase\Test is excluded because its final
     * segment is Test.
     *
     * @param  Node[]  $ast
     */
    private function declaresTestCaseClass(array $ast): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($ast, Class_::class) as $class) {
            if ($class->name !== null
                && $class->extends !== null
                && preg_match('/TestCase$/', $this->resolvedLast($class->extends)) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Final segment of the resolved name — the value every routing comparison runs on. */
    public function resolvedLast(Name $name): string
    {
        return $this->resolved($name)->getLast();
    }
}
