<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Third Party Services
    |---------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    // Anthropic / Claude — used by the AI team-builder (AiAutoAssignController)
    // and AI contract drafting (ContractDraftService). Without a key both
    // features silently fall back to deterministic heuristics.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
        // Override when going through a proxy / reseller (e.g. vibecode-claude.online).
        // No trailing slash — code appends "/v1/messages".
        'base_url' => rtrim(env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
        'schedule_retries'     => (int) env('ANTHROPIC_SCHEDULE_RETRIES', 2),
        'schedule_max_tokens'  => (int) env('ANTHROPIC_SCHEDULE_MAX_TOKENS', 16384),
        'schedule_model'       => env('ANTHROPIC_SCHEDULE_MODEL', 'claude-3-5-sonnet-latest'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

];
