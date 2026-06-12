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
