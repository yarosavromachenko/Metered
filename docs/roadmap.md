# Roadmap

Ten milestones. The rule that shapes all of them: **the repository is
presentable after every single one** — green CI, accurate README, a demo that
starts. There is no "it will make sense once M7 lands" state.

Sizes are for one developer: S ≈ 1–2 days, M ≈ 3–5 days, L ≈ 1–2 weeks.

| | Milestone | Size | Status |
|---|---|---|---|
| M0 | Foundation | S–M | ✅ done |
| M1 | Shared kernel | M | ✅ done |
| M2 | Tenancy and the admin shell | M | ✅ done |
| M3 | Usage ingestion | L | ✅ done |
| M4 | Billing catalog and subscriptions | M | ✅ done |
| M5 | Invoicing and ledger | L | ✅ done |
| M6 | Webhooks | M | ✅ done |
| M7 | Simulation, seed profiles and chaos | M | 🔜 next |
| M8 | Observability polish | S–M | ⬜ |
| M9 | Release polish | S | ⬜ |

The admin panel is not a milestone of its own. It grows inside each milestone,
next to the domain it exposes — otherwise it would always be one step behind the
data model, which is exactly how admin panels rot.

---

## M0 — Foundation

Repository skeleton, Laravel with Octane and FrankenPHP, docker compose (app,
postgres, pgbouncer, redis), Makefile, quality tool configuration, CI, commit
hooks, documentation skeleton.

- [x] `make up && make check` green on a clean clone
- [x] CI green
- [x] A demonstration arch test fails when `Domain` imports `Illuminate\*` or calls `now()` — verified by writing the violation and watching three tests and both Deptrac configurations reject it
- [x] ADR-0001, ADR-0009, ADR-0013 accepted

## M1 — Shared kernel

Clock (PSR-20) and adapters, `Money`/`BigDecimal` wrappers, UUIDv7, outbox writer
and relay, inbox, idempotency middleware, hash-chained audit log, problem+json
error handling, OpenTelemetry bootstrap with propagation into queues.

- [x] Concurrency test: 16 parallel processes with one `Idempotency-Key` → one execution, fifteen `409`s
- [x] Crash between commit and dispatch → the relay still delivers, and the inbox makes the effect exactly once
- [x] `audit:verify` detects an altered row, a removed row, and a hash rewritten to match altered contents
- [x] A trace started in an HTTP request continues inside a queued job — same trace id, the job's span hanging off the request's
- [x] Three relays publishing forty messages produce forty publications and no duplicate
- [x] ADR-0005, ADR-0006, ADR-0007, ADR-0014 accepted

## M2 — Tenancy and the admin shell

Organizations, projects, API keys (hashed, scoped, revocable), authentication
middleware that is Octane-safe, rate limiting, `org:create`. Admin users, their
membership in an organization, roles, the Filament panel, login, demo-tenant
sign-up, project switcher.

- [x] An API key secret is never stored and never logged (test)
- [x] Octane state-leak test: two sequential requests from different tenants share nothing
- [x] Key revocation takes effect within 30 seconds (test with a controlled clock)
- [x] Tenant A cannot see or mutate tenant B's data through any admin screen (test)
- [x] ADR-0013, ADR-0017 accepted

Row-level security was the stretch goal here and did not land; isolation does
not depend on it, and it is listed under what is deliberately not here in the
README.

## M3 — Usage ingestion

Batch endpoint, Redis Stream producer, consumer daemon (groups, ack,
`XAUTOCLAIM`, dead-letter stream, graceful shutdown), bulk insert with aggregates
in one transaction, partition management, deduplication, backpressure,
rejections, reconciliation, usage queries. Admin: usage explorer, rejections,
aggregates, a stream-lag widget.

- [x] Integration tests: duplicates, redelivery, poison message → DLQ, late and future events
- [x] Kill the consumer mid-batch → after restart `usage:reconcile` reports zero drift — tested for a death before the commit and between the commit and the acknowledgement, and run for real: 200,000 events queued, the consumer `SIGKILL`ed with a batch unacknowledged, all 200,000 written once after restart, no drift. The repeatable version is `sim:chaos` in M7
- [x] k6 baseline recorded in [`benchmarks.md`](benchmarks.md) — and failing its p99 threshold (570ms against 150ms), which stands until M8's tracing names the cause
- [x] `EXPLAIN (ANALYZE, BUFFERS)` of the hot queries in [`query-plans.md`](query-plans.md) — five captured, the invoice build provisional until M5, webhooks waiting for M6
- [x] ADR-0002, ADR-0003, ADR-0004 accepted

A minimal slice of M4 was built here: meters and customers, defined in the
panel, because an event cannot be resolved to a meter and a customer that do not
exist yet. Plans, prices, subscriptions and the catalog's API stay in M4
([`assumptions.md`](assumptions.md), 16).

## M4 — Billing catalog and subscriptions

Meters, plans, versions, prices, customers, subscriptions with phases, period
arithmetic (anchor, month-end clamp), and the pricing calculator as a pure domain
service. Admin: CRUD for all of it.

- [x] Table-driven tests for all four pricing models, with cases sitting exactly on tier boundaries — on each boundary, a millionth past it and one unit past it, including the quantity where graduated and volume must disagree
- [x] Time-travel tests: 31 Jan → 28/29 Feb, year rollover, DST independence (everything is UTC) — plus a MockClock walked a day at a time through a leap year holding the no-gap, no-overlap invariant, and subscriptions changed and canceled across the February clamp
- [x] Mutation score ≥ 85 on `Billing/Domain` — measured on the module alone with `make mutation-module MODULE=Billing`, since the combined score could hide it

The rules the domain types enforce are also in the schema, for rows written
without them: a published version and its prices refuse any change, two phases
of one subscription cannot overlap (an exclusion constraint), a phase cannot
name a draft, and a price or subscription cannot reach another project's meter
or customer. Admin: plans, plan versions with their prices, and subscriptions,
beside the meters and customers built in M3; the management API covers all of
it under an admin key ([`api.md`](api.md)).

## M5 — Invoicing and ledger

`billing:close-periods`, per-subscription jobs, invoice lines built from
aggregates, grace window and late events, finalization with gapless numbering,
ledger entries, fake payment gateway behind a port, credit notes, void, PDF,
outbox events. Admin: invoice list, a detail page that shows how each line was
computed, PDF, void and pay actions, ledger view.

Stretch: immediate plan change with proration of fixed fees.

- [x] Concurrency test: two parallel closes of one subscription → exactly one invoice — run with eight workers in real processes
- [x] Concurrency test: 50 parallel finalizations → numbering with no gaps and no duplicates — ten of them rolling back after taking a number, which must come back
- [x] Ledger invariant: debits equal credits; `UPDATE`/`DELETE` rejected by the database — in the domain type, and again by a deferred constraint trigger at commit; `UPDATE`, `DELETE` and `TRUNCATE` refused by triggers
- [x] Time-travel tests across period edges and the grace window; a late event lands on the next invoice — a microsecond before the window closes, usage inside the window, a late event six days after its period's invoice, and a scheduler that was down for three periods
- [x] Mutation score ≥ 85 on `Invoicing/Domain` — 100% (89 of 89), measured with `make mutation-module MODULE=Invoicing`
- [x] ADR-0008, ADR-0010 accepted

Invoices carry who they were addressed to, as they were, and every line keeps
the steps that priced it — the invoice's page, its PDF and the API all show
them. The management API reads, pays and voids invoices under an admin key
([`api.md`](api.md)). The stretch goal, proration, did not land and is the
first cut the plan names; plan changes still take effect at period end. A
`scheduler` service now runs `routes/console.php`, which nothing did before.

## M6 — Webhooks

Endpoint CRUD, signing and secret rotation, delivery pipeline, retries with
backoff and jitter, dead-letter queue with replay, circuit breaker, SSRF guard,
inbox-based consumption of integration events. Admin: endpoints, delivery log,
manual replay, breaker state.

- [x] Signature test vectors plus a verification example in [`webhooks.md`](webhooks.md) — PHP and Node, with vectors for one secret, two during a rotation and an empty body, pinned by the signer's tests
- [x] SSRF tests: private address, redirect, DNS rebinding — plus the metadata address, IPv6 loopback, a host with one public and one private record, IPv4-mapped and NAT64 forms, and every range's edge
- [x] Circuit breaker state machine test: closed → open → half-open → closed — in the domain, and again through the delivery pipeline, with a probe that fails and one that never reports
- [x] After ten failures the event is dead-lettered and can be replayed — from the API, the panel or `webhooks:replay`, starting again from the first attempt with the same body
- [x] ADR-0011 accepted

Subscriptions now announce `subscription.created` and `subscription.canceled`
through the outbox, so five events are delivered; `usage.threshold_reached` was
cut, as the scope order allows. The management API covers endpoints, rotation,
deliveries and replay ([`api.md`](api.md)). Two bugs outside the module turned
up on the way: the event dispatcher could run its handlers for one message per
worker, and the first delivery on the running stack could not pin its address;
both are fixed and tested.

## M7 — Simulation, seed profiles and chaos

`sim:seed` with `small`/`demo`/`heavy` profiles, `sim:backfill` for bulk history,
`sim:traffic`, `sim:chaos`, `sim:time-travel`, k6 scenarios, `demo:reset`.

- [ ] `make demo` on a clean clone brings up the stack, fills it, and shows live load in Grafana
- [ ] The `demo` profile produces roughly two million usage events over 90 days in under five minutes
- [ ] Every chaos scenario ends with a green invariant check
- [ ] ADR-0016 accepted

## M8 — Observability polish

Grafana dashboards (ingestion, stream lag, outbox lag, webhooks, billing),
Prometheus alert rules committed to the repository, an end-to-end trace spanning
HTTP → stream → consumer → database → outbox → queue → webhook. Health endpoints:
`GET /health/live` (the process answers) and `GET /health/ready` (PostgreSQL,
Redis and the ingestion backlog are within bounds), used by the compose
healthchecks.

- [ ] Screenshots of the end-to-end trace and the dashboards in [`observability.md`](observability.md)
- [ ] `/health/ready` fails when PostgreSQL or Redis is unreachable, or the backlog is past the backpressure threshold (test)
- [ ] ADR-0012 accepted

## M9 — Release polish

README with positioning, quick start, diagrams and badges; final benchmark
numbers; the trade-offs and not-implemented section; a review of every ADR;
[`runbook.md`](runbook.md); a recorded walkthrough of the admin panel; CHANGELOG;
tag `v1.0.0`; image published to GHCR. Stretch: Kubernetes manifests.

- [ ] Someone who has never seen the project starts it and understands the architecture within ten minutes
- [ ] No `TODO`/`FIXME` without a linked issue, and no commented-out code

---

## Scope discipline

If the work overruns, this is the order things get cut, decided in advance so the
decision is not made under pressure: proration on plan change → the
`usage.threshold_reached` event → row-level security → PDF styling → Kubernetes
manifests. Anything cut moves to the "not implemented" section of the README,
with the reason.
