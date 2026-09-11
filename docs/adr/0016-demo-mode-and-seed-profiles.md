# 0016. Demo mode and seed profiles

- **Status:** Accepted in M7
- **Date:** 2026-05-31, accepted 2026-09-11

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

**Demo mode** is `APP_DEMO=true`, honoured only in the `local`, `demo` and
`testing` environments; anywhere else the application refuses to boot with it.
It opens self-service sign-up on the panel.

**Two kinds of demo tenant**, both flagged `demo` when created, a flag the
database holds fixed:

- **The showcase** — one organization, "Northwind Cloud", seeded by
  `make demo` with the `demo` profile and entered through a fixed read-only
  (viewer) login printed on the sign-in page. Everyone looks at the same
  months of history, and nobody can change it.
- **A visitor's own tenant** — what sign-up creates: an organization, a
  project, an owner and a key, filled in the background with the `small`
  profile in a few seconds. It is theirs to break; "Reset demo data" on the
  projects page empties it and seeds it again, keeping members, projects and
  keys.

Demo tenants nobody has signed in to for seven days are purged by a daily
sweep, invoices and ledger included — the only deletes the append-only
invoicing tables admit, and only for an organization the transaction has
declared it is purging and the database knows to be a demo. The showcase is
exempt from the sweep. `demo:reset` purges every demo tenant, the showcase
too, and `make demo-reset` seeds the showcase again.

**Seed profiles:**

| Profile | Volume | Use |
|---|---|---|
| `small` | 12 customers, ~30k events over 60 days | Every visitor's tenant; development |
| `demo` | 120 customers, ~2M events over 90 days, ~230 invoices | The showcase |
| `heavy` | 1,000 customers, ~20M events over 90 days | Benchmarks and index work |

**Two seeding paths, deliberately, inside one command.** `sim:seed` creates
the catalog, webhook endpoints, customers and backdated subscriptions through
the **public HTTP API**, then:

1. loads the history older than the last few days with `COPY` — events and
   the hourly aggregates the consumer would have written — and runs
   `usage:reconcile`, which must report zero drift before anything is built
   on it;
2. closes every period that has ended with the ordinary period close;
3. settles those invoices through the API the way customers do — most paid
   some days late, a few left overdue, one voided with a credit note;
4. sends the last days of usage through the API, some of it late for periods
   already closed, some twice, some naming a meter or customer that does not
   exist.

`sim:traffic` then keeps sending live usage through the API, and `sim:chaos`
operates against the same running stack.

The data includes awkward cases on purpose: subscriptions anchored on the
29th, 30th and 31st; a customer who never uses anything; one who changes plan;
one who cancels; one on the volume-priced plan; duplicates, late events and
rejections; one webhook endpoint per mode of the demo receiver — accepting,
flaky, slow, down (its breaker opens) and gone.

## Changes from the proposal

- **No per-visitor copy of the demo dataset.** Two million events per
  sign-up would take minutes and gigabytes each. Visitors share the showcase
  read-only and get a `small` tenant of their own, seeded in seconds; the
  shared state the "single account" alternative suffered from cannot occur,
  because the shared account cannot write.
- **No separate `sim:backfill` command.** The bulk path needs the customers,
  meters and subscriptions the API stage just created; as a step of
  `sim:seed` it gets them without a second command re-deriving them. It is
  still a separate class (`CopyHistoryLoader`), named as a bypass, and
  followed by the reconciliation check.
- **One organization, one project, one currency** in the showcase rather
  than three organizations and two currencies. Grouping by currency is
  proven by the dashboards' tests; seeding it too added time and nothing a
  reviewer could see that the tests do not show.
- **A voided invoice with a credit note, not a "voided and re-issued" one.**
  The invoicing domain has no re-issue: a corrected bill is the next period's
  late lines.
- **No additional caps on demo tenants.** They are bounded by the per-key
  rate limit every tenant has and by the idle purge; nothing a visitor can do
  in a demo tenant reaches another tenant.

## Consequences

`make demo` produces a system that looks like it has been running for months, in
minutes, on a laptop. Graphs have shape, invoices have history, and the tier
boundaries in the pricing calculator are visible in real invoice lines.

Nothing is exposed to the internet, so there is no abuse surface, no bill, and no
stale deployment contradicting the README.

The history loader bypasses the ingestion path, which is precisely what the
ingestion design is about. That is why it is a class of its own named for what
it does, why the reconciliation check runs after it and stops the seed if it
finds drift, and why it is documented here rather than hidden inside a seeder.

The demo dataset has a size floor: roughly 4 GB of RAM, and a few minutes on
first run — about two for the seed itself on a laptop.

Self-service sign-up in an application that also runs in production mode is a
risk managed by a boot-time refusal rather than by a configuration convention.

## Alternatives considered

**A hosted demo.** Best possible reviewer experience and a standing commitment:
hosting cost, patching, abuse handling, and data reset. Rejected deliberately —
see the constraint above.

**A single seeded admin account with fixed credentials.** Simpler, and every
visitor shares state; the first person to void an invoice changes what the next
person sees. The showcase keeps the fixed credentials and removes the problem by
making them read-only.

**Seed everything through the API.** Purest, and hours long for two million
events. Rejected in favour of a documented bulk path plus a reconciliation check.

**Ship a database dump.** Fast to load and opaque: a binary blob in the
repository that nobody can review and that breaks on every schema change.
