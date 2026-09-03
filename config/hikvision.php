<?php

return [
    // Whether THIS deployment is allowed to write to physical terminals (add/remove persons,
    // cards, faces, alcohol config, event-listening config). Reads are always allowed.
    //
    // A physical terminal must be driven by exactly ONE environment. On 2026-09-03 both the
    // local dev stack and production were syncing the same device every 15 minutes with
    // divergent emp_code→person mappings, which scrambled ~30 person/card/face records and
    // made people authenticate as a colleague. Default: on in production, off everywhere else.
    // Override with HIKVISION_SYNC_ENABLED when a non-prod env legitimately owns its own device.
    'sync_enabled' => (bool) env('HIKVISION_SYNC_ENABLED', env('APP_ENV') === 'production'),

    // Secret path segment for the real-time event push webhook (routes/api.php) — Hikvision
    // terminals POST here directly, so this stands in for auth since there's no session/CSRF.
    'webhook_token' => env('HIKVISION_WEBHOOK_TOKEN'),

    // Full external base URL (scheme + host [+ port]) that terminals can reach us at — e.g.
    // the ngrok forwarding URL during development, a real public domain in production. This is
    // deliberately separate from APP_URL: APP_URL is what browsers use and (per this project's
    // setup) doesn't reliably reflect what a terminal on a different network segment can reach.
    'webhook_base_url' => env('HIKVISION_WEBHOOK_BASE_URL'),
];
