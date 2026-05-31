# 0016. Demo mode and seed profiles

- **Status:** Proposed
- **Date:** 2026-05-31

## Context

The repository is meant to be run, not only read. A reviewer should reach a
populated admin panel from a clean clone without reading instructions first.

Two constraints shape how:

**No hosted instance.** A public deployment costs money, needs securing, and
rots the moment it is forgotten. The demo therefore runs entirely from Docker
Compose on the reviewer's machine.

**An empty system demonstrates nothing.** Pricing tiers, stream lag, partition
pruning and invoice history are only visible against a realistic volume of data.
That means millions of events — and seeding millions of events through the HTTP
API would take far longer than anyone will wait.

## Decision

**Demo mode** is `APP_DEMO=true`. It opens self-service sign-up on the panel:
a visitor gets their own organization, project, API key and a copy of the demo
dataset, fully isolated. Demo tenants are limited in what they may do, and are
deleted seven days after their last sign-in. Demo mode and real credentials must
never be enabled together, and the application refuses to boot if they are.

**Seed profiles:**

| Profile | Volume | Use |
|---|---|---|
| `small` | thousands of events | Development and tests |
| `demo` | ~2M events over 90 days, 120 customers, 4 plans covering all pricing models | `make demo` |
| `heavy` | ~20M events | Benchmarks and index work |

**Two seeding paths, deliberately:**

- `sim:seed` and `sim:traffic` drive the **public HTTP API**. That is the only
  way the ingestion path is exercised end to end, and it is what `sim:chaos`
  operates against.
- `sim:backfill` bulk-loads history with `COPY`, writing events and aggregates
  in exactly the shape the consumer would have produced. `usage:reconcile` runs
  afterwards and must report zero drift — that check is what keeps the shortcut
  honest.

The `demo` dataset includes awkward cases on purpose: subscriptions anchored on
the 29th, 30th and 31st; duplicate events; late events; a customer with zero
usage; a voided and re-issued invoice; an endpoint with an open circuit breaker.

## Consequences

`make demo` produces a system that looks like it has been running for months, in
minutes, on a laptop. Graphs have shape, invoices have history, and the tier
boundaries in the pricing calculator are visible in real invoice lines.

Nothing is exposed to the internet, so there is no abuse surface, no bill, and no
stale deployment contradicting the README.

`sim:backfill` bypasses the ingestion path, which is precisely what the ingestion
design is about. That is why it is a separate command with a different name, why
the reconciliation check is mandatory after it, and why it is documented here
rather than hidden inside a seeder.

The demo dataset has a size floor: roughly 4 GB of RAM, and a few minutes on
first run. A reviewer on a small machine can use `small` instead, and the README
says so.

Self-service sign-up in an application that also runs in production mode is a
risk managed by a boot-time refusal rather than by a configuration convention.

## Alternatives considered

**A hosted demo.** Best possible reviewer experience and a standing commitment:
hosting cost, patching, abuse handling, and data reset. Rejected deliberately —
see the constraint above.

**A single seeded admin account with fixed credentials.** Simpler, and every
visitor shares state; the first person to void an invoice changes what the next
person sees.

**Seed everything through the API.** Purest, and hours long for two million
events. Rejected in favour of a documented bulk path plus a reconciliation check.

**Ship a database dump.** Fast to load and opaque: a binary blob in the
repository that nobody can review and that breaks on every schema change.
