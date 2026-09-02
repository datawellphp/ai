<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tool Mode
    |--------------------------------------------------------------------------
    |
    | How the registry is projected onto tools (D60). 'perSource' generates a
    | query tool per source and a tool per server action — the most context
    | for the model. 'compact' publishes a fixed list (list_sources,
    | describe_source, query, act) for large registries. Either way the
    | shared lookup, values and progress tools are included.
    |
    */

    'mode' => 'perSource',

    /*
    |--------------------------------------------------------------------------
    | Chart Tools
    |--------------------------------------------------------------------------
    |
    | Whether to publish chart tools (D63): a tool per source (or one shared
    | `chart` tool in compact mode) whose result is a typed payload
    | (`kind: chart`) your chat front-end renders — with the buckets included
    | so the model can still narrate. Turn off when your interface cannot
    | draw charts.
    |
    */

    'charts' => true,

];
