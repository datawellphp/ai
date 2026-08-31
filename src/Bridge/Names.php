<?php

declare(strict_types=1);

namespace Datawell\Ai\Bridge;

/**
 * Tool names are identifiers for the model: source and action keys flattened to
 * snake case, so `document-signatures` + `send_reminder` reads as
 * `document_signatures_send_reminder`.
 */
final class Names
{
    public static function tool(string ...$parts): string
    {
        $joined = implode('_', array_map(static fn (string $part): string => self::snake($part), $parts));

        return trim((string) preg_replace('/_+/', '_', $joined), '_');
    }

    public static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value));
    }
}
