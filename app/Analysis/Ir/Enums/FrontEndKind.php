<?php

declare(strict_types=1);

namespace App\Analysis\Ir\Enums;

enum FrontEndKind: string
{
    case PhpUnit = 'phpunit';
    case Pest = 'pest';
}
