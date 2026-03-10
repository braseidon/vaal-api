<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth Credentials
    |--------------------------------------------------------------------------
    |
    | Your GGG OAuth application credentials. Register at:
    | https://www.pathofexile.com/developer/apps
    |
    */

    'client_id'     => env('POE_CLIENT_ID'),
    'client_secret' => env('POE_CLIENT_SECRET'),
    'redirect_uri'  => env('POE_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | OAuth scopes to request during authorization. Empty by default;
    | configure based on your application's needs. See Scope enum for
    | available values and shortcuts (allAccount, allService, all).
    |
    */

    'scopes' => [],

    /*
    |--------------------------------------------------------------------------
    | User-Agent
    |--------------------------------------------------------------------------
    |
    | GGG requires a User-Agent header with your app name and contact email.
    | Format: OAuth {client_id}/{version} (contact: {email})
    |
    */

    'user_agent' => [
        'version' => env('POE_API_VERSION', '1.0.0'),
        'contact' => env('POE_API_CONTACT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Two-layer system:
    |
    | 1. Pre-flight checks - Before each request, tracked rate limit state
    |    is checked. The "strategy" controls what happens when a limit is
    |    predicted to be exceeded:
    |      - "sleep"     Sleep the required duration, then proceed
    |      - "exception" Throw RateLimitException immediately
    |      - "callback"  Call the configured callback
    |      - "log"       Log a warning and continue
    |
    | 2. Retry middleware - If a 429/503 response slips through, Guzzle
    |    middleware reads Retry-After and retries automatically. Controlled
    |    by "auto_retry" and "max_retries".
    |
    | Safety margin: Treat rate limits as if they were this percentage lower
    | than reported. 0.2 means a 10-request limit is treated as 8.
    |
    */

    'rate_limit' => [
        'strategy'      => env('POE_RATE_LIMIT_STRATEGY', 'sleep'),
        'safety_margin' => (float) env('POE_RATE_LIMIT_SAFETY_MARGIN', 0.2),
        'auto_retry'    => (bool) env('POE_RATE_LIMIT_AUTO_RETRY', true),
        'max_retries'   => (int) env('POE_RATE_LIMIT_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Settings
    |--------------------------------------------------------------------------
    */

    'timeout'       => (int) env('POE_API_TIMEOUT', 30),
    'default_realm' => env('POE_DEFAULT_REALM'),

    /*
    |--------------------------------------------------------------------------
    | API Base URLs
    |--------------------------------------------------------------------------
    |
    | Override for testing or if GGG changes their API URLs.
    |
    */

    'base_url'   => env('POE_API_BASE_URL', 'https://api.pathofexile.com'),
    'public_url' => env('POE_PUBLIC_URL', 'https://www.pathofexile.com'),

];
