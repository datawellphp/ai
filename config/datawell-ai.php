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

];
