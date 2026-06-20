<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Transactional outbox
    |--------------------------------------------------------------------------
    |
    | The relay is a long-running daemon holding a connection across many
    | transactions, and it uses SELECT ... FOR UPDATE SKIP LOCKED. PgBouncer's
    | transaction pooling hands a backend to whoever asks next, so the daemon
    | connects to PostgreSQL directly instead.
    |
    */

    'outbox' => [
        'connection' => env('OUTBOX_CONNECTION', 'pgsql_direct'),
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),

        // After this many failed publications a message is left alone for a
        // human. Retrying forever turns one poison message into an outage.
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 10),

        // How long the daemon waits when it finds nothing to do.
        'idle_sleep_seconds' => (float) env('OUTBOX_IDLE_SLEEP_SECONDS', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | API keys
    |--------------------------------------------------------------------------
    |
    | Authentication happens on every API request, so the key lookup is cached.
    | The TTL is also the bound on revocation: a key revoked while its entry is
    | live stops working within this window even if the invalidation that
    | accompanies the write never reaches this node. Thirty seconds is the
    | number documented in docs/api.md, and lengthening it lengthens the
    | promise.
    |
    */

    'api_keys' => [
        'cache_ttl_seconds' => (int) env('API_KEY_CACHE_TTL_SECONDS', 30),

        // How coarsely `last_used_at` is maintained. A write per request would
        // double the write load of ingestion for a column read by humans.
        'usage_recording_interval_seconds' => (int) env('API_KEY_USAGE_INTERVAL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    |
    | One trace is meant to span HTTP → Redis stream → consumer → PostgreSQL →
    | outbox → queue → webhook. Disabled turns the provider into a no-op rather
    | than putting a condition at every call site.
    |
    */

    'tracing' => [
        'enabled' => env('OTEL_SDK_DISABLED', 'true') !== 'true',
        'service_name' => env('OTEL_SERVICE_NAME', 'metered'),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://otel-collector:4318'),
    ],

];
