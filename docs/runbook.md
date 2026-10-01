# Runbook

Operational procedures. Written as if someone other than the author is on call,
because that is the only way to find out whether they make sense.

## Health

| Check | Meaning |
|---|---|
| `GET /health/live` | The process is up. Never touches dependencies. Always `200`. |
| `GET /health/ready` | `200` when every check passes, `503` otherwise: `database` (a query on the web connection), `redis` (`PING`), `usage_backlog` (pending events below `USAGE_STREAM_BACKPRESSURE`). |

A liveness probe that checks dependencies restarts a healthy application when a
database blips. Keep them separate.

The readiness body names each check, so the failing one is read off the probe:

```json
{"status": "not_ready", "checks": {
  "database": {"status": "pass", "detail": "ok"},
  "redis": {"status": "fail", "detail": "unreachable"},
  "usage_backlog": {"status": "pass", "detail": "120 pending of 500000"}}}
```

"unreachable" is all the endpoint says about a failed dependency; the
exception behind it is logged as `Readiness check failed.` with the check's
name. `usage_backlog` fails at exactly the depth where ingestion starts
answering 503 — see the next section.

Compose uses readiness for the `app` service (`docker compose ps` shows it
unhealthy); the image's own `HEALTHCHECK` uses liveness, since an orchestrator
restarts on it. The worker daemons (`usage-consumer`, `outbox-relay`,
`horizon`, `scheduler`) serve no HTTP and have no healthcheck: their liveness
is the process running, answered by the restart policy.

## Alerts

Prometheus evaluates `docker/prometheus/rules/metered.yml`; Alertmanager
sends what fires by email, one message per alert name, to Mailpit
(http://localhost:8025, `ops@metered.test`). What is firing right now is on
http://localhost:9093.

| Alert | Fires when | Section |
|---|---|---|
| `IngestionErrors` | over 5% of ingestion requests answer a 5xx other than 503, for 2 min | [Ingestion is failing with 5xx](#ingestion-is-failing-with-5xx) |
| `IngestionSheddingLoad` | any 503 in the last 5 min, for 1 min | [Ingestion is returning 503](#ingestion-is-returning-503) |
| `IngestionLatencyHigh` | p99 of accepted requests above 150 ms, for 5 min | [Ingestion is slow](#ingestion-is-slow) |
| `UsageBacklogHigh` | backlog above 250 000, half the backpressure threshold, for 2 min | [Ingestion is returning 503](#ingestion-is-returning-503) |
| `UsageRedisMemoryHigh` | the usage Redis above 80% of its `maxmemory`, for 5 min | [The usage Redis is running out of memory](#the-usage-redis-is-running-out-of-memory) |
| `UsageDeadLettersGrowing` | the dead-letter stream grew in the last 15 min | [Messages in the dead-letter stream](#messages-in-the-dead-letter-stream) |
| `OutboxLagging` | the oldest unpublished message is over 2 min old, for 2 min | [Outbox is falling behind](#outbox-is-falling-behind) |
| `WebhookBreakerOpen` | an endpoint's breaker is open, for 1 min (one line per endpoint) | [A webhook endpoint is failing](#a-webhook-endpoint-is-failing) |
| `WebhookSuccessRateLow` | under 90% of attempts delivered over 15 min, for 10 min | [A webhook endpoint is failing](#a-webhook-endpoint-is-failing) |
| `TelemetryCollectorDown` | Prometheus cannot scrape the collector, for 1 min | [Telemetry is missing](#telemetry-is-missing) |
| `GaugesMissing` | no gauge reported for 2 min | [Telemetry is missing](#telemetry-is-missing) |

`UsageBacklogHigh` holds its threshold as a number; change it together with
`USAGE_STREAM_BACKPRESSURE`. Each rule has a test in `metered.test.yml`, run
by `make alerts-test` and the CI job `alerts`.

## After a migration: `cached plan must not change result type`

Queries through PgBouncer fail with `SQLSTATE[0A000] ... cached plan must not
change result type` right after a migration that adds or drops a column.
Seen when `webhook_deliveries` gained `trace_context`: every
`webhooks:dispatch` run failed until the pool was reset.

PgBouncer keeps prepared statements on its server connections
(`MAX_PREPARED_STATEMENTS`). A statement prepared as `select * from <table>`
before the migration has a result shape the table no longer has, and
PostgreSQL refuses to run the cached plan.

1. Run migrations first, then reset the pool so every server connection starts
   without cached statements: `docker compose restart pgbouncer`. Clients
   reconnect on their next query.
2. Nothing needs replaying. The failed commands were scheduled passes
   (`webhooks:dispatch` every 10s, `billing:close-periods` every five minutes)
   and the next pass after the reset does their work.

Make the reset part of every deploy that changes a table's columns.

## Ingestion is returning 503

Backpressure is working as designed: the backlog crossed its threshold, or the
usage Redis reached its `maxmemory` (the problem's detail says which; for the
second, see [The usage Redis is running out of memory](#the-usage-redis-is-running-out-of-memory)).

1. Grafana → Metered — Ingestion: the stream backlog against its threshold, and
   when the 503s started. `curl localhost:8080/health/ready` gives the backlog
   as `usage_backlog`.
2. Is the consumer running? `docker compose ps usage-consumer`.
3. If it is alive but slow, look at `usage_batch_write_duration_seconds`. A jump usually means a missing partition, so the insert hit the default partition.
4. `php artisan usage:partitions:ensure` creates missing partitions for the next N days. It is idempotent.
5. Scale consumers with `docker compose up -d --scale usage-consumer=4`. Consumer groups distribute the load without configuration.

Do not raise the backpressure threshold to make the 503s stop. That converts a
visible, retryable rejection into unbounded lag.

## The usage Redis is running out of memory

`redis-usage` holds the ingestion stream and one deduplication key per event
for seven days (ADR-0002, "Capacity"). At its `maxmemory` it refuses writes,
and ingestion answers 503 until there is room. The consumer stalls with it —
its deduplication claims are writes too — so nothing frees memory by itself
before keys expire; step 2 is what ends it. Nothing outside ingestion is
affected.

1. How full, and what fills it:
   `docker compose exec redis-usage redis-cli info memory | grep -E 'used_memory_human|maxmemory_human'`,
   then `redis-cli -n 2 xlen usage:events` for the stream. A long stream is a
   consumer that is behind — [Ingestion is returning 503](#ingestion-is-returning-503).
   A short stream means the deduplication keys: sustained traffic outgrew the limit.
2. Raise the limit without a restart:
   `docker compose exec redis-usage redis-cli config set maxmemory 2gb`, then
   set `REDIS_USAGE_MAXMEMORY` in `.env` so the next start keeps it. Size it
   from the table in ADR-0002 — events per second × 604 800 × 200 bytes.
3. Do not delete `usage:dedup:*` keys or switch to an evicting policy to make
   room. Every key removed lets a resent event be counted twice.

## Ingestion is failing with 5xx

Not backpressure — that is a 503 and has its own section. A 500 is the
application failing to accept a batch.

1. The request span in Tempo (Grafana → Explore → Tempo, search
   `{ name = "POST /api/v1/usage/events" && status = error }`) carries the
   exception; its `trace_id` finds the log line: `docker compose logs app | grep <trace id>`.
2. Almost always Redis: `curl localhost:8080/health/ready`. The stream is the
   only thing ingestion writes to.
3. A client retrying after a 5xx sends the same event ids, and the consumer
   counts each id once, so nothing is double-counted once it recovers.

## Ingestion is slow

The alert is on accepted requests only; a slow 503 is still a 503.

1. Grafana → Metered — Ingestion → Latency, and the traces behind the slow
   ones: `{ name = "POST /api/v1/usage/events" && duration > 150ms }`.
2. Ingestion normally touches no database — authentication reads a cached key
   and the batch goes to Redis. A slow span shows which middleware held the
   request.
3. Compare with the k6 baseline in `docs/benchmarks.md` before changing
   anything: the threshold is the benchmark's.

## Telemetry is missing

- `TelemetryCollectorDown`: `docker compose ps otel-collector`. While it is
  down, spans exported meanwhile are dropped, not buffered, and every failed
  export costs its process about half a second of retries — after the
  response for the web workers, so ingestion p99 barely moves
  ([`benchmarks.md`](benchmarks.md)). Counters are
  cumulative, so the next export after it is back carries the totals; only
  the graph has a gap (ADR-0019).
- `GaugesMissing`: `docker compose ps metrics-observer`, and its log. The
  counters still arrive from the processes; stream depth, outbox lag, queue
  depth and breakers do not. `php artisan metrics:observe --once` reads them
  once and says how many readings it took.

## The consumer died mid-batch

Nothing is lost by design, but verify it rather than believing it:

1. Restart the consumer. It reclaims messages idle for more than 60s (`XPENDING`, then `XCLAIM`).
2. `php artisan usage:reconcile --from="-2 hours"` compares aggregates against raw events.
3. Expect zero drift. A non-zero result is a bug worth an issue, not a manual correction.

## Messages in the dead-letter stream

```bash
php artisan usage:dead-letters --limit=20        # newest first, with the reason
php artisan usage:dead-letters:replay <id> <id>  # or --all
```

The reason decides what to do:

| Reason | Meaning | What to do |
|---|---|---|
| `too_many_deliveries` | Writing it failed five times in a row | Find why in the consumer's log (`Batch failed: …`), fix that, then replay |
| `malformed` | The message could not be read as an event; the tenant sees a `malformed` rejection | Nothing to replay — the sender has to send it again, correctly. Replay refuses it |
| `project_gone` | Its project was deleted (a demo reset or purge) before it was written | Nothing; there is nowhere for it to go. Replay refuses it |

Replay is safe to repeat. It moves the message back to the ingestion stream and
removes it from the dead-letter stream in one transaction, and the event keeps
its id and `occurred_at`, so the database writes it once however often it is
replayed. It also keeps the time it was first received, and the acceptance
window is judged against that: an event that waited weeks in the dead-letter
stream is still accepted. If its period has been invoiced since, it is billed
as a late line on the next invoice.

The command exits non-zero when any id was refused or not found, so a script
replaying a list notices the ones that did not go back. It checks the reason
recorded when the message was set aside, not the project: an entry whose
project was deleted since is replayed, and comes back as `project_gone`.

## Outbox is falling behind

`outbox_unpublished_age_seconds` is the signal.

1. Is `outbox-relay` running?
2. Are the queues draining? Check Horizon.
3. The relay uses `FOR UPDATE SKIP LOCKED`, so multiple relays are safe: scale it.
4. Never delete an unpublished outbox row to clear a backlog. That drops an event whose state change already committed.

Published rows are removed by `outbox:prune`, daily at 03:20, once they are
older than `OUTBOX_RETENTION_DAYS` (7). `php artisan outbox:prune --days=N` runs
it by hand; it never removes an unpublished row.

## Invoices are not being built

A period is invoiced one grace window (an hour) after it ends, by
`billing:close-periods`, which the `scheduler` service runs every five minutes.

1. Is `scheduler` running? `docker compose ps scheduler`.
2. Run it by hand: `php artisan billing:close-periods`. It prints how many closes
   it queued; zero means no subscription has a period past its grace window.
3. Are the `billing` jobs failing? Check Horizon's failed jobs. A worker started
   before a deploy runs the old code: `php artisan horizon:terminate` restarts it.
4. Running the command or a job twice is safe. The unique key on
   `(subscription, period)` lets one invoice in; a second close finds it there.

## An invoice is stuck as a draft

The close finalizes what it builds. A draft left behind means the finalization
failed after the draft was committed. Open it in the panel and finalize or
discard it. Never edit an invoice or a ledger row by hand: the schema refuses,
and a correction is a credit note.

## A webhook endpoint is failing

1. Admin panel → Webhooks → Endpoints shows the breaker; Deliveries, filtered
   by status, shows what failed, and each delivery's page every attempt with its
   answer, duration, error and the first kilobyte of what the receiver said.
2. An open breaker means deliveries are waiting, not lost, and not spending
   their attempts. It lets one probe through five minutes after it opened.
3. A `failed` delivery with an error such as "resolves to 10.0.0.5, which
   webhooks may not reach" was refused by the SSRF guard: the URL points
   somewhere private. Nothing was sent.
4. Sustained `4xx` other than 408/429 means the receiver is rejecting the
   payload — an integration problem, not a delivery problem. Those deliveries
   are `failed` at once.
5. After the receiver is fixed, replay what died, from the panel, from the API,
   or by id: `php artisan webhooks:replay <delivery-id>`.
6. Nothing is being attempted at all? `webhooks:dispatch` runs every ten seconds
   from the `scheduler` service, and the `webhooks` queue runs on Horizon —
   check both are up, and restart Horizon after a deploy.

## Rotating a webhook secret

1. `POST /webhook-endpoints/{id}/rotate-secret`, or Rotate secret in the panel.
   The answer shows the new secret once. From now on both secrets sign every
   delivery.
2. The receiver adds the new secret to its verification, within a day.
3. The old secret stops signing by itself when the day is over.

Do not leave step two for later. Both signatures are sent precisely so there is
no window in which a correct receiver rejects a valid delivery, and the window
closes after a day (`WEBHOOKS_ROTATION_GRACE_SECONDS`).

## Revoking an API key

Admin panel → API keys → Revoke. Keys are cached for up to 30 seconds
(`API_KEY_CACHE_TTL_SECONDS`), so the key stops working within that window.
Revocation cannot be undone.

## Rotating an API key

There is no in-place rotation: a key is replaced by a new one, and both work
until the old one is revoked.

1. Issue a new key in the panel, with the same scopes. Its secret is shown once.
2. Switch the client to it.
3. Watch the old key's *last used* in the panel stop moving — that is the proof
   nothing still sends it.
4. Revoke the old key.

## Idempotency records

A key is remembered for 24 hours (ADR-0006). `idempotency:purge` removes
expired records every hour; `php artisan idempotency:purge` runs it by hand and
prints how many went. An expired record is ignored when its key comes back
whether or not it has been purged, so a late purge costs disk, not correctness.

## Verifying the audit log

`php artisan audit:verify` walks every chain — one per organization, plus the
platform chain of entries from before 1.1.0 (ADR-0020). A failure means a row
was altered outside the application, and the command reports the first broken
link and the chain it is in.
The scheduler runs it daily at 03:50; a failed run is in the scheduler's log.
