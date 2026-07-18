<?php

namespace App\Analysis\Ir\Enums;

/**
 * Canonical test-type classification. Derived from real signals (base class, traits,
 * HTTP/DB API usage), never from directory names. Every classification also carries the
 * rule that produced it (see TestTypeClassifier) so the decision is auditable.
 */
enum TestType: string
{
    case Unit = 'unit';
    case Feature = 'feature';
    case Integration = 'integration';
    case Unknown = 'unknown';
}
