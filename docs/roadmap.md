# Roadmap

Ten milestones. The rule that shapes all of them: **the repository is
presentable after every single one** — green CI, accurate README, a demo that
starts. There is no "it will make sense once M7 lands" state.

Sizes are for one developer: S ≈ 1–2 days, M ≈ 3–5 days, L ≈ 1–2 weeks.

| | Milestone | Size | Status |
|---|---|---|---|
| M0 | Foundation | S–M | ✅ done |
| M1 | Shared kernel | M | 🔜 next |
| M2 | Tenancy and the admin shell | M | ⬜ |
| M3 | Usage ingestion | L | ⬜ |
| M4 | Billing catalog and subscriptions | M | ⬜ |
| M5 | Invoicing and ledger | L | ⬜ |
| M6 | Webhooks | M | ⬜ |
| M7 | Simulation, seed profiles and chaos | M | ⬜ |
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

- [ ] Concurrency test: N parallel requests with one `Idempotency-Key` → one execution, the rest replayed or `409`
- [ ] Crash between commit and dispatch → the relay still delivers, and the inbox makes the effect exactly once
- [ ] `audit:verify` detects a manually tampered row
- [ ] A trace started in an HTTP request continues inside a queued job
- [ ] ADR-0005, ADR-0006, ADR-0007 accepted

## M2 — Tenancy and the admin shell

Organizations, projects, API keys (hashed, scoped, revocable), authentication
middleware that is Octane-safe, rate limiting, `org:create`. Admin users, their
membership in an organization, roles, the Filament panel, login, demo-tenant
sign-up, project switcher.

- [ ] An API key secret is never stored and never logged (test)
- [ ] Octane state-leak test: two sequential requests from different tenants share nothing
- [ ] Key revocation takes effect within 30 seconds (test with a controlled clock)
- [ ] Tenant A cannot see or mutate tenant B's data through any admin screen (test)
- [ ] ADR-0013, ADR-0017 accepted

## M3 — Usage ingestion

Batch endpoint, Redis Stream producer, consumer daemon (groups, ack,
`XAUTOCLAIM`, dead-letter stream, graceful shutdown), bulk insert with aggregates
in one transaction, partition management, deduplication, backpressure,
rejections, reconciliation, usage queries. Admin: usage explorer, rejections,
aggregates, a stream-lag widget.

- [ ] Integration tests: duplicates, redelivery, poison message → DLQ, late and future events
- [ ] Kill the consumer mid-batch → after restart `usage:reconcile` reports zero drift
- [ ] k6 baseline recorded in [`benchmarks.md`](benchmarks.md)
- [ ] `EXPLAIN (ANALYZE, BUFFERS)` of the hot queries in [`query-plans.md`](query-plans.md)
- [ ] ADR-0002, ADR-0003, ADR-0004 accepted

## M4 — Billing catalog and subscriptions

Meters, plans, versions, prices, customers, subscriptions with phases, period
arithmetic (anchor, month-end clamp), and the pricing calculator as a pure domain
service. Admin: CRUD for all of it.

- [ ] Table-driven tests for all four pricing models, with cases sitting exactly on tier boundaries
- [ ] Time-travel tests: 31 Jan → 28/29 Feb, year rollover, DST independence (everything is UTC)
- [ ] Mutation score ≥ 85 on `Billing/Domain`

## M5 — Invoicing and ledger

`billing:close-periods`, per-subscription jobs, invoice lines built from
aggregates, grace window and late events, finalization with gapless numbering,
ledger entries, fake payment gateway behind a port, credit notes, void, PDF,
outbox events. Admin: invoice list, a detail page that shows how each line was
computed, PDF, void and pay actions, ledger view.

Stretch: immediate plan change with proration of fixed fees.

- [ ] Concurrency test: two parallel closes of one subscription → exactly one invoice
- [ ] Concurrency test: 50 parallel finalizations → numbering with no gaps and no duplicates
- [ ] Ledger invariant: debits equal credits; `UPDATE`/`DELETE` rejected by the database
- [ ] Time-travel tests across period edges and the grace window; a late event lands on the next invoice
- [ ] Mutation score ≥ 85 on `Invoicing/Domain`
- [ ] ADR-0008, ADR-0010 accepted

## M6 — Webhooks

Endpoint CRUD, signing and secret rotation, delivery pipeline, retries with
backoff and jitter, dead-letter queue with replay, circuit breaker, SSRF guard,
inbox-based consumption of integration events. Admin: endpoints, delivery log,
manual replay, breaker state.

- [ ] Signature test vectors plus a verification example in [`webhooks.md`](webhooks.md)
- [ ] SSRF tests: private address, redirect, DNS rebinding
- [ ] Circuit breaker state machine test: closed → open → half-open → closed
- [ ] After ten failures the event is dead-lettered and can be replayed
- [ ] ADR-0011 accepted

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
HTTP → stream → consumer → database → outbox → queue → webhook.

- [ ] Screenshots of the end-to-end trace and the dashboards in [`observability.md`](observability.md)
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
