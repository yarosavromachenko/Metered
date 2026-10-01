# Changelog

Every milestone on the [roadmap](docs/roadmap.md) is a release. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html): the
public API is the HTTP API under `/api/v1` and the webhook payloads.

## [Unreleased]

### Fixed
- A first start no longer corrupts `APP_KEY`. Every service used to seed the
  shared `.env` at the same moment, and the interleaved writes left a key no
  cipher accepts, so the app exited on boot. `make install` now writes the key
  once before any container starts; on a bare `docker compose up` only the
  `app` service writes it and the others wait.
- A usage event whose quantity is too large to store (10^14 or more) is
  refused with a `422` naming the event. It used to be accepted with a `202`,
  then failed the write of every event of its tenant read with it, and all of
  them reached the dead-letter stream together.
- When the database refuses a tenant's write for its data, the consumer
  writes the batch in halves until only the event that causes it is left
  pending; its neighbours are written and acknowledged in the same pass
  ([ADR-0003](docs/adr/0003-redis-streams-ingestion.md)).
- A batch's rejections are recorded once its write commits, so a failed write
  that is retried no longer records them once per attempt.
- The consumer looks an unknown meter code or customer reference up once per
  batch. A miss used to be looked up again for every event that named it, so
  a client sending a misconfigured code cost one query per event.

### Security
- The webhook guard accepts IPv6 only from global unicast `2000::/3`, minus
  `2001::/23`, `2001:db8::/32`, `2002::/16` and `3fff::/20`. It used to refuse
  a list of ranges, which let 6to4, Teredo, local-use NAT64 and
  IPv4-compatible addresses through to the IPv4 host they carry.

## [1.0.0] - 2026-09-30

Release polish: the repository as a reviewer meets it.

### Added
- `usage:dead-letters` and `usage:dead-letters:replay`: list what the consumer
  set aside and move it back to the stream in one transaction; replaying twice
  writes once ([ADR-0003](docs/adr/0003-redis-streams-ingestion.md)).
- Scheduled maintenance the decisions promised: `idempotency:purge` hourly,
  `outbox:prune` daily, `audit:verify` daily
  ([ADR-0005](docs/adr/0005-transactional-outbox-inbox.md),
  [ADR-0006](docs/adr/0006-api-idempotency.md),
  [ADR-0014](docs/adr/0014-audit-log-hash-chain.md)).
- A recorded walkthrough of the admin panel and screenshots in the README.
- Query plans captured on the `heavy` profile with other tenants alongside, and
  the release benchmark on the same dataset: ingestion p99 38.6 ms at 22
  million events, plus a first `mixed` run
  ([query plans](docs/query-plans.md), [benchmarks](docs/benchmarks.md)).
- Runbook procedures for every dead-letter reason and for rotating an API key.
- This changelog.

### Changed
- The production image is built on a runtime stage with no compiler, headers,
  Composer or git; pull requests scan it with Trivy before it reaches `main`.
- Tenants in one consumer read are written independently: one tenant's failed
  write no longer holds back or dead-letters the others.
- The ADRs, README and docs were checked against the code and describe the
  finished system.

### Fixed
- Signing in to the panel through its form stored the user's UUID cast to an
  integer, and every page after it failed. The auth provider pointed at the
  framework's skeleton user model.
- An idempotency key never expired: a claim ignored the 24-hour window and
  nothing purged old records.
- Events of a project deleted while they waited in the stream failed the whole
  read on a foreign key; they are now dead-lettered as `project_gone`.
- Caddy could not write its state as the image's non-root user.
- `sim:seed --profile=heavy` ran out of memory on its last stage: the live day
  was built whole before it was sent. It is sent as it is generated.
- A concurrency test compared results in the order processes finished.
- `.env.example` named three ingestion settings nothing read.
- `league/commonmark` upgraded to 2.10.3, past GHSA-3q6v-r5mr-hxv8 (quadratic
  time on crafted GitHub Flavored Markdown) and GHSA-97jj-33gv-5xf9
  (`DisallowedRawHtml` bypass).

### Removed
- The framework skeleton's user model, factory and seeder.

## [0.9.0] - 2026-09-22

M8 — observability.

### Added
- Traces across every asynchronous hop: HTTP → Redis stream → consumer batch
  (linked to the requests it wrote) → outbox → queue → webhook receiver
  ([ADR-0012](docs/adr/0012-trace-context-propagation.md)).
- Metrics exported through OpenTelemetry to the collector, gauges read in one
  process ([ADR-0019](docs/adr/0019-metrics-through-opentelemetry.md)); four
  Grafana dashboards for ingestion, processing, delivery and billing.
- Prometheus alert rules with `promtool` tests, delivered by Alertmanager to
  Mailpit.
- `GET /health/live` and `GET /health/ready`, used by the compose healthchecks.

### Fixed
- The ingestion p99 of 570 ms: a telemetry export ran inside the request. It is
  37 ms now ([benchmarks](docs/benchmarks.md)).
- Telemetry providers rebuilt on every Octane request.
- Counters invisible to `rate()` until a series' second sample.
- Anonymous Grafana viewers turned away from Explore, where the traces are.

## [0.8.0] - 2026-09-11

M7 — simulation, seed profiles and chaos.

### Added
- `sim:seed` with `small`, `demo` and `heavy` profiles and a bulk history
  stage; `sim:traffic`, `sim:chaos` with five scenarios, `sim:time-travel`
  ([ADR-0016](docs/adr/0016-demo-mode-and-seed-profiles.md)).
- `make demo`: the stack, a seeded showcase, a read-only viewer login and live
  traffic in one command; self-service demo tenants that reset from the panel
  and are purged when idle.
- A live-load dashboard in Grafana.

### Fixed
- Tracing switched on everywhere by an `env()` boolean.
- An overlap lock that held for a day after a scheduler restart.
- The simulation client resending throttled usage before `Retry-After`.
- A deadlock when a webhook endpoint was removed during a delivery.

## [0.7.0] - 2026-08-26

M6 — webhooks.

### Added
- Signed webhooks with secret rotation, retries with backoff and jitter, a
  circuit breaker per endpoint, a dead-letter state with replay, and an SSRF
  guard with DNS-rebinding protection
  ([ADR-0011](docs/adr/0011-webhook-delivery.md)).
- `subscription.created` and `subscription.canceled` events next to the
  invoice events; the management API and panel screens for endpoints and
  deliveries.

### Fixed
- The integration event dispatcher ran handlers for only one message per worker.

## [0.6.0] - 2026-08-19

M5 — invoicing and ledger.

### Added
- `billing:close-periods`, invoices built from aggregates with a grace window
  and late lines, finalization with gapless numbering
  ([ADR-0010](docs/adr/0010-period-close-and-invoice-numbering.md)).
- An append-only double-entry ledger enforced by the database
  ([ADR-0008](docs/adr/0008-double-entry-ledger.md)); a fake payment gateway
  behind a port; credit notes, voids and PDFs.
- Invoice screens that show how each line was computed.

## [0.5.0] - 2026-07-25

M4 — billing catalog and subscriptions.

### Added
- Meters, plans with published versions and prices, customers, subscriptions
  with phases, and period arithmetic with month-end clamping.
- A pure-domain pricing calculator for flat, per-unit, graduated and volume
  pricing ([ADR-0007](docs/adr/0007-money-and-decimals.md)).
- The management API and panel screens for the catalog.

## [0.4.0] - 2026-07-15

M3 — usage ingestion.

### Added
- `POST /usage/events` into a Redis stream, a consumer daemon writing to
  partitioned PostgreSQL with aggregates in the same transaction
  ([ADR-0002](docs/adr/0002-partitioning-and-deduplication.md),
  [ADR-0003](docs/adr/0003-redis-streams-ingestion.md),
  [ADR-0004](docs/adr/0004-aggregation-exactly-once-effect.md)).
- Deduplication, backpressure, rejections, reconciliation, partition management.
- The first k6 baseline and captured query plans.

## [0.3.0] - 2026-06-26

M2 — tenancy and the admin shell.

### Added
- Organizations, projects, hashed and scoped API keys, rate limiting
  ([ADR-0013](docs/adr/0013-multi-tenancy.md)).
- The Filament panel with members, roles and demo sign-up
  ([ADR-0015](docs/adr/0015-admin-ui-filament.md),
  [ADR-0017](docs/adr/0017-admin-authentication.md)).

## [0.2.0] - 2026-06-12

M1 — shared kernel.

### Added
- Clock, money and decimals, UUIDv7, the transactional outbox and inbox,
  idempotency keys, a hash-chained audit log, problem+json errors
  ([ADR-0005](docs/adr/0005-transactional-outbox-inbox.md),
  [ADR-0006](docs/adr/0006-api-idempotency.md),
  [ADR-0014](docs/adr/0014-audit-log-hash-chain.md)).
- Design principles held by architecture tests: every abstraction earns its
  place ([ADR-0018](docs/adr/0018-design-principles.md)).

## [0.1.0] - 2026-06-05

M0 — foundation.

### Added
- Laravel with Octane on FrankenPHP, PostgreSQL behind PgBouncer, Redis, docker
  compose, a Makefile, and CI with every quality gate
  ([ADR-0001](docs/adr/0001-modular-monolith.md),
  [ADR-0009](docs/adr/0009-clock-injection.md)).
