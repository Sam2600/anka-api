<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'anthropic' => [
        'api_key'              => env('ANTHROPIC_API_KEY'),
        'schedule_retries'     => (int) env('ANTHROPIC_SCHEDULE_RETRIES', 2),
        'schedule_max_tokens'  => (int) env('ANTHROPIC_SCHEDULE_MAX_TOKENS', 16384),
        'schedule_model'       => env('ANTHROPIC_SCHEDULE_MODEL', 'claude-3-5-sonnet-latest'),
    ],

];
