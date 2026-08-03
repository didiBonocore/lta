<?php

declare(strict_types=1);

namespace App\Analysis\FrontEnd;

use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Explains why a file was declined by every front end, without changing what routes: the
 * base class from the extends clause of the first class declaration, resolved through
 * file-level use statements where possible (the raw name where not). Where the file has no
 * class and no top-level it()/test() call, a sentinel string records that instead, so the
 * next excluded framework can be judged on evidence rather than inferred from a bare count.
 */
final class UnroutableClassifier
{
    public const NO_CLASS_NO_TEST_CALL = '(no class or top-level test call)';

    public const CLASS_WITHOUT_PARENT = '(class without extends clause)';

    public const UNPARSEABLE = '(unparseable)';

    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function baseClassOf(string $source): string
    {
        try {
            $ast = $this->parser->parse($source);
        } catch (ParseError) {
            return self::UNPARSEABLE;
        }

        if ($ast === null) {
            return self::UNPARSEABLE;
        }

        $finder = new NodeFinder;
        $class = $finder->findFirstInstanceOf($ast, Class_::class);
        if ($class === null) {
            return self::NO_CLASS_NO_TEST_CALL;
        }

        if ($class->extends === null) {
            return self::CLASS_WITHOUT_PARENT;
        }

        return $this->resolveThroughUses($class->extends, $ast);
    }

    /**
     * Resolve a parent-class name through the file's use statements: a fully-qualified name
     * is stored as written; a name whose first segment matches an imported alias is expanded
     * to the import target; anything else is stored raw.
     *
     * @param  Node\Stmt[]  $ast
     */
    private function resolveThroughUses(Name $name, array $ast): string
    {
        if ($name instanceof Name\FullyQualified) {
            return $name->toString();
        }

        $imports = $this->classImports($ast);
        $alias = strtolower($name->getFirst());

        if (! isset($imports[$alias])) {
            return $name->toString();
        }

        $rest = array_slice($name->getParts(), 1);

        return implode('\\', [$imports[$alias], ...$rest]);
    }

    /**
     * File-level class imports as alias (lowercased) => fully-qualified target.
     *
     * @param  Node\Stmt[]  $ast
     * @return array<string, string>
     */
    private function classImports(array $ast): array
    {
        $finder = new NodeFinder;
        $imports = [];

        foreach ($finder->findInstanceOf($ast, Use_::class) as $use) {
            if ($use->type !== Use_::TYPE_NORMAL) {
                continue;
            }
            foreach ($use->uses as $item) {
                $imports[strtolower($item->getAlias()->toString())] = $item->name->toString();
            }
        }

        foreach ($finder->findInstanceOf($ast, GroupUse::class) as $group) {
            foreach ($group->uses as $item) {
                if (($item->type === Use_::TYPE_UNKNOWN ? $group->type : $item->type) !== Use_::TYPE_NORMAL) {
                    continue;
                }
                $imports[strtolower($item->getAlias()->toString())] = $group->prefix->toString().'\\'.$item->name->toString();
            }
        }

        return $imports;
    }
}
