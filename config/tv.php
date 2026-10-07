<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display TV Token
    |--------------------------------------------------------------------------
    |
    | Read-only wallboard page (/tv) has no user login, so access is guarded
    | by this shared secret passed as ?token=... in the URL. Generate a long
    | random value. When empty, every /tv request is refused.
    |
    */

    'token' => env('TV_DISPLAY_TOKEN'),

];
