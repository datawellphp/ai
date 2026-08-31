<?php

declare(strict_types=1);

namespace Datawell\Ai\Adapters\LaravelAi;

use Datawell\Ai\Bridge\Mode;
use Datawell\Ai\Bridge\ToolDefinition;
use Datawell\Ai\Bridge\ToolSet;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The one-liner for an agent's tools(): every Datawell tool this user may use.
 *
 *     public function tools(): iterable { return DatawellTools::for($this->user); }
 */
final class DatawellTools
{
    /**
     * @return list<BridgeTool>
     */
    public static function for(Authenticatable $user, ?Mode $mode = null): array
    {
        return array_map(
            static fn (ToolDefinition $definition): BridgeTool => new BridgeTool($definition),
            app(ToolSet::class)->for($user, $mode),
        );
    }
}
