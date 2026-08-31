<?php

declare(strict_types=1);

namespace Datawell\Ai\Bridge;

/**
 * How the registry is projected onto tools (D60).
 */
enum Mode: string
{
    /** One query tool per source and one tool per server action — the default. */
    case PerSource = 'perSource';

    /** A fixed, small tool list with a source-key argument — for large registries. */
    case Compact = 'compact';
}
