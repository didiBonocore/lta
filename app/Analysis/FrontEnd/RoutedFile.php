<?php

declare(strict_types=1);

namespace App\Analysis\FrontEnd;

use PhpParser\Node;

/**
 * A routing decision together with the parse tree it was made on, so the chosen front end
 * consumes the SAME tree instead of parsing the file a second time. Parsing is the
 * pipeline's dominant cost; the tree carries only NameResolver attributes (no node was
 * replaced), so it is structurally identical to a fresh parse of the source.
 */
final readonly class RoutedFile
{
    /** @param Node[] $statements */
    public function __construct(
        public FrontEnd $frontEnd,
        public array $statements,
    ) {}
}
