<?php

namespace App\Analysis\Ir\Enums;

enum FrontEndKind: string
{
    case PhpUnit = 'phpunit';
    case Pest = 'pest';
}
