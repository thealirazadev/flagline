<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ruleset retention
    |--------------------------------------------------------------------------
    |
    | How many published ruleset versions to keep per environment. Only the
    | latest is ever served; older rows are kept for debugging and pruned on a
    | schedule.
    |
    */

    'ruleset_keep' => (int) env('FLAGLINE_RULESET_KEEP', 50),

    /*
    |--------------------------------------------------------------------------
    | Ruleset document schema version
    |--------------------------------------------------------------------------
    |
    | Describes the shape of the published document. SDKs hard-code the schema
    | versions they understand and discard anything else.
    |
    */

    'schema_version' => 1,

];
