<?php

declare(strict_types=1);

namespace App\Analysis\Extraction;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Small helper: recover the called method/function name from any call node, or null when
 * the name is dynamic (e.g. $this->$name()). Dynamic calls are deliberately not counted;
 * this limitation is recorded as a threat to validity.
 */
final class CallName
{
    public static function of(Node $node): ?string
    {
        $name = match (true) {
            $node instanceof MethodCall,
            $node instanceof NullsafeMethodCall,
            $node instanceof StaticCall => $node->name,
            $node instanceof FuncCall => $node->name,
            default => null,
        };

        if ($name instanceof Identifier) {
            return $name->toString();
        }
        if ($name instanceof Name) {
            return $name->getLast(); // last segment, e.g. Http\Client -> Client
        }

        return null; // dynamic / unrecoverable
    }

    /** The receiver's class for a static call, e.g. "Http" in Http::fake(). */
    public static function staticClass(StaticCall $node): ?string
    {
        return $node->class instanceof Name ? $node->class->getLast() : null;
    }
}
