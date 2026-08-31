<?php

declare(strict_types=1);

namespace Datawell\Ai\Adapters\LaravelAi;

use Datawell\Ai\Bridge\ToolDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * One bridge tool as a laravel/ai tool. Consent rides on the framework's approvals
 * (D59): when the bridge says a call needs approval, the framework pauses and asks
 * the user, showing the bridge's reason; when it resumes, the tool-call id becomes
 * the D40 approval reference the executor requires and the D45 audit records.
 */
class BridgeTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(protected ToolDefinition $definition) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function description(): string
    {
        return $this->definition->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->definition->schema($schema);
    }

    public function handle(Request $request): string
    {
        $approved = $this->shouldRequestApproval($request) !== null;

        $result = $this->definition->handle(
            $request->all(),
            $approved ? ($request->toolCallId() ?? 'approved') : null,
        );

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $reason = $this->definition->approvalReason($request->all());

        return $reason === null ? false : Approval::required($reason);
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }
}
