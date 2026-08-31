<?php

declare(strict_types=1);

namespace Datawell\Ai\Bridge;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * One tool as the bridge sees it, independent of any agent framework: a name, the
 * AI-facing description, an input schema built on Illuminate's JsonSchema contract,
 * the handler, and — for actions — the per-call approval decision (D59).
 */
final class ToolDefinition
{
    /**
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @param  Closure(array<string, mixed>, ?string): array<string, mixed>  $handler  arguments + approval reference → result payload
     * @param  (Closure(array<string, mixed>): ?string)|null  $approval  arguments → reason when approval is required, null when not
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly Closure $schema,
        public readonly Closure $handler,
        public readonly ?Closure $approval = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ($this->schema)($schema);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments, ?string $approval = null): array
    {
        return ($this->handler)($arguments, $approval);
    }

    /**
     * The reason this call needs the user's approval, or null when it may run as is.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function approvalReason(array $arguments): ?string
    {
        return $this->approval === null ? null : ($this->approval)($arguments);
    }

    public function isApprovable(): bool
    {
        return $this->approval !== null;
    }
}
