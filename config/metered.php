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

        // A demo tenant nobody has signed in to for this long is deleted by
        // the daily tenancy:purge-idle-demos, invoices and all.
        'idle_days' => (int) env('DEMO_IDLE_DAYS', 7),

        // The seeded organization a visitor can look around before signing
        // up (`make demo`), and the read-only account that signs in to it.
        // It is a demo, so demo:reset can rebuild it, but the idle sweep
        // never removes it.
        'showcase' => env('DEMO_SHOWCASE', 'northwind-cloud'),
        'showcase_login' => [
            'email' => env('DEMO_SHOWCASE_EMAIL', 'demo@metered.test'),
            'password' => env('DEMO_SHOWCASE_PASSWORD', 'metered-demo'),
        ],
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
    | Usage ingestion
    |--------------------------------------------------------------------------
    |
    | The hot path answers 202 once the batch is in the stream, and everything
    | below describes what happens after that (ADR-0003). The daemon connects
    | to PostgreSQL directly rather than through PgBouncer, for the same reason
    | the outbox relay does.
    |
    */

    'usage' => [
        'connection' => env('USAGE_CONNECTION', 'pgsql_direct'),

        // Events per request. A hundred keeps one request's work bounded —
        // both the JSON a worker decodes and the pipeline it writes — while
        // being large enough that a busy client is not making a request per
        // event.
        'batch_limit' => (int) env('USAGE_BATCH_LIMIT', 100),

        // How far an event's own timestamp may sit from now and still be
        // counted. Seven days back is the promise in docs/api.md — lengthening
        // it means accepting events for periods that may be invoiced. Five
        // minutes forward is clock drift, not a feature.
        'acceptance' => [
            'max_age_seconds' => (int) env('USAGE_MAX_AGE_SECONDS', 7 * 24 * 60 * 60),
            'max_drift_seconds' => (int) env('USAGE_MAX_DRIFT_SECONDS', 300),
        ],

        'stream' => [
            'connection' => env('USAGE_STREAM_CONNECTION', 'usage'),
            'key' => env('USAGE_STREAM_KEY', 'usage:events'),
            'dead_letter_key' => env('USAGE_STREAM_DLQ_KEY', 'usage:events:dead'),
            'group' => env('USAGE_STREAM_GROUP', 'usage-writers'),

            // Approximate trimming: exact trimming makes XADD walk the stream,
            // and the whole point of this path is that XADD is cheap.
            'max_length' => (int) env('USAGE_STREAM_MAX_LENGTH', 1_000_000),

            // Above this backlog — events accepted but not yet written —
            // ingestion answers 503 with Retry-After rather than accepting
            // work it is visibly failing to drain. It is deliberately a
            // fraction of max_length: the stream keeps entries after they
            // have been written, so its length is not a measure of anything
            // being behind.
            'backpressure_threshold' => (int) env('USAGE_STREAM_BACKPRESSURE', 500_000),
            'retry_after_seconds' => (int) env('USAGE_STREAM_RETRY_AFTER', 5),
        ],

        'consumer' => [
            'batch_size' => (int) env('USAGE_CONSUMER_BATCH', 500),
            'block_milliseconds' => (int) env('USAGE_CONSUMER_BLOCK_MS', 2000),

            // A message nobody acknowledged within this long is assumed to
            // belong to a consumer that died, and is reclaimed.
            'reclaim_idle_milliseconds' => (int) env('USAGE_CONSUMER_RECLAIM_MS', 60_000),

            // After this many deliveries a message goes to the dead-letter
            // stream. Retrying a poison message forever is how one bad payload
            // becomes an outage.
            'max_deliveries' => (int) env('USAGE_CONSUMER_MAX_DELIVERIES', 5),
        ],

        // The deduplication layer the database cannot provide: it catches a
        // resend that changed `occurred_at` (ADR-0002). Seven days matches the
        // acceptance window, because that is how long a resend can matter.
        'deduplication' => [
            'ttl_seconds' => (int) env('USAGE_DEDUP_TTL_SECONDS', 7 * 24 * 60 * 60),
        ],

        'partitions' => [
            'days_ahead' => (int) env('USAGE_PARTITION_DAYS_AHEAD', 7),

            // Only ever acted on when the command is asked to prune. Raw
            // events are what `usage:reconcile` checks aggregates against, so
            // dropping them is an operator's decision, not a default.
            'retention_days' => (int) env('USAGE_PARTITION_RETENTION_DAYS', 400),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoicing
    |--------------------------------------------------------------------------
    |
    | A period is invoiced one grace window after it ends, so that events in
    | flight at the boundary still make it onto its invoice (ADR-0010). Usage
    | that reaches an earlier period after that is billed on the next invoice
    | as a late line; how far back that can happen follows from how old an
    | event may be when it is accepted.
    |
    */

    'invoicing' => [
        'grace_seconds' => (int) env('INVOICING_GRACE_SECONDS', 60 * 60),

        // Also queues nothing for a subscription that ended longer ago than
        // this: its last period was invoiced long since.
        'ended_lookback_seconds' => (int) env('INVOICING_ENDED_LOOKBACK_SECONDS', 31 * 24 * 60 * 60),

        'queue' => env('INVOICING_QUEUE', 'billing'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Deliveries are rows; the dispatcher queues whatever is due every ten
    | seconds, and a worker makes one attempt (ADR-0011). Five failures in a
    | row open an endpoint's breaker for five minutes. A new secret signs
    | alongside the old one for a day.
    |
    */

    'webhooks' => [
        'queue' => env('WEBHOOKS_QUEUE', 'webhooks'),
        'breaker_threshold' => (int) env('WEBHOOKS_BREAKER_THRESHOLD', 5),
        'breaker_cooldown_seconds' => (int) env('WEBHOOKS_BREAKER_COOLDOWN_SECONDS', 300),
        'rotation_grace_seconds' => (int) env('WEBHOOKS_ROTATION_GRACE_SECONDS', 24 * 60 * 60),

        // Longer than an attempt can take (10s total), so a delivery is not
        // handed to a second worker while the first is still waiting on it.
        'lease_seconds' => (int) env('WEBHOOKS_LEASE_SECONDS', 60),

        // Plain http, for a receiver on a developer's machine or the demo's
        // own. Never outside local and demo: the URL check refuses it there.
        'allow_http' => in_array(env('APP_ENV'), ['local', 'demo'], true),

        // The demo's own webhook receiver, as host:port, which the SSRF guard
        // lets through although it is on the private network. Exactly one
        // host and port; honoured only in local and demo, and anywhere else
        // the application refuses to boot (ADR-0011).
        'trusted_destination' => env('WEBHOOKS_TRUSTED_DESTINATION'),
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
        // env() hands back the string "true" as a boolean, so the value is
        // read as one: compared with the string, it was never equal, and
        // tracing was on wherever OTEL_SDK_DISABLED said it was off.
        'enabled' => ! filter_var(env('OTEL_SDK_DISABLED', true), FILTER_VALIDATE_BOOL),
        'service_name' => env('OTEL_SERVICE_NAME', 'metered'),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://otel-collector:4318'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Simulation
    |--------------------------------------------------------------------------
    |
    | Where the dev-only simulation finds the platform it drives. The API URL
    | is the app as another container reaches it — the seed goes through the
    | network like any client (ADR-0016) — and the receiver is the demo's own
    | webhook receiver, the one private destination the SSRF guard admits.
    |
    */

    'simulation' => [
        'api_url' => env('SIM_API_URL', 'http://app:8080'),
        'receiver_url' => env('SIM_RECEIVER_URL', 'http://webhook-receiver:8080'),
    ],
];
