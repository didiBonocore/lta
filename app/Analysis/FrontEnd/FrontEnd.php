<?php

declare(strict_types=1);

namespace App\Analysis\FrontEnd;

use App\Analysis\Ir\TestFileRecord;
use PhpParser\Node;

interface FrontEnd
{
    /** Parse one test file's source into the canonical IR. */
    public function parse(string $path, string $source): ?TestFileRecord;

    /**
     * Build the IR from an already-parsed tree — the single-parse path used with
     * FrontEndRouter, which hands over the tree it routed on.
     *
     * @param  Node[]  $statements
     */
    public function parseStatements(string $path, array $statements): ?TestFileRecord;
}
