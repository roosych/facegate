<?php

return [
    // Secret path segment for the real-time event push webhook (routes/api.php) — Hikvision
    // terminals POST here directly, so this stands in for auth since there's no session/CSRF.
    'webhook_token' => env('HIKVISION_WEBHOOK_TOKEN'),

    // Full external base URL (scheme + host [+ port]) that terminals can reach us at — e.g.
    // the ngrok forwarding URL during development, a real public domain in production. This is
    // deliberately separate from APP_URL: APP_URL is what browsers use and (per this project's
    // setup) doesn't reliably reflect what a terminal on a different network segment can reach.
    'webhook_base_url' => env('HIKVISION_WEBHOOK_BASE_URL'),
];
