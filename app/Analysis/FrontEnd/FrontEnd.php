<?php

namespace App\Analysis\FrontEnd;

use App\Analysis\Ir\TestFileRecord;

interface FrontEnd
{
    /** True if this front-end should own the given file (by content shape). */
    public function handles(string $source): bool;

    /** Parse one test file's source into the canonical IR. */
    public function parse(string $path, string $source): ?TestFileRecord;
}
