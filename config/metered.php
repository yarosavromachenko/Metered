<?php

declare(strict_types=1);

use Metered\Tenancy\Presentation\Filament\Auth\RegisterTenant;

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | Demo mode opens self-service sign-up in the panel: a visitor creates
    | their own organization, project and API key (ADR-0016). It is off by
    | default, and the panel simply has no registration route when it is —
    | the page is named here rather than wired into the panel, so turning the
    | demo off removes the screen rather than hiding it.
    |
    */

    'demo' => [
        'enabled' => filter_var(env('APP_DEMO', false), FILTER_VALIDATE_BOOL),
    ],

    'admin' => [
        'registration_page' => filter_var(env('APP_DEMO', false), FILTER_VALIDATE_BOOL)
            ? RegisterTenant::class
            : null,
    ],

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

        // Requests per minute per key. Ingestion sends batches of up to a
        // hundred events, so this is a far larger budget than it looks.
        'rate_limit_per_minute' => (int) env('API_KEY_RATE_LIMIT_PER_MINUTE', 600),
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
