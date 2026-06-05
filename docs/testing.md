# Testing strategy

Every level below exists because it catches a class of bug the others cannot.
Where a level would only re-test what a cheaper one already proves, it is not
written — a suite nobody trusts is worse than a smaller suite that is always
meaningful.

| Suite | What it proves | Needs |
|---|---|---|
| `Unit` | Pure domain logic: pricing, period arithmetic, ledger invariants, signature computation | Nothing. Milliseconds. |
| `Feature` | HTTP contracts, authorization, validation, problem+json shape, admin screens | Laravel, SQLite/Postgres |
| `Integration` | Repositories, outbox relay, stream consumer, partition management | Real PostgreSQL and Redis |
| `Concurrency` | What happens when two processes race: idempotency keys, period close, invoice numbering, credit application | Real parallel connections |
| `Architecture` | Layering and module boundaries, banned helpers, no `float` money, no writes from presentation | Nothing |

```bash
make test               # everything, with the coverage threshold
make test-unit          # fast loop while writing domain logic
make test-integration   # needs the containers up
make test-concurrency   # slow; run before pushing
make test-arch          # cheap; run constantly
make mutation           # Infection on the four domain layers
```

## Rules that are not negotiable

**Concurrency tests use real, separate connections.** A concurrency test wrapped
in `RefreshDatabase` runs inside one transaction and therefore proves the
opposite of what it claims: the second "process" sees the first one's uncommitted
data, and a race that would fail in production passes in CI. These tests use
`spatie/fork` to run each actor in its own process with its own connection, and
they clean up explicitly.

**Time is injected, never waited for.** Domain and application code depends on
`Psr\Clock\ClockInterface`; tests supply a mock clock and move it. There is no
`sleep()` anywhere in the suite, and no test that only passes on a day that is
not the 31st.

**Integration tests use the real thing.** A mocked Redis proves the mock works.
The consumer's behaviour under redelivery, `XAUTOCLAIM` and dead-lettering only
exists against a real stream, so CI runs service containers.

## The cases that must exist

These are written down because they are the ones that are easy to skip and
expensive to miss.

**Pricing**

- A quantity landing exactly on a tier boundary, for both `graduated` and
  `volume` — and the two must produce different totals.
- Zero usage on a usage-based price: a line of zero, not a missing line.
- A quantity with six decimal places, proving rounding happens once, at the line.

**Periods**

- Anchored on the 31st: January → February in a leap and a non-leap year.
- Year rollover.
- An event arriving inside the grace window versus one arriving after it.
- A late event landing on the next invoice, flagged, not silently merged.

**Ingestion**

- The same `event_id` submitted twice in one batch, and twice in different batches.
- Redelivery after the consumer is killed between the database commit and `XACK`.
- A poison message that fails parsing: dead-lettered, not blocking the group.
- An event older than the acceptance window and one five minutes in the future.

**Concurrency**

- N parallel requests carrying one `Idempotency-Key`: one execution, the others
  replayed or rejected with `409`.
- Two parallel closes of the same subscription period: exactly one invoice, and
  the second failure is the unique constraint, not a lock timeout.
- Fifty parallel finalizations: numbering with no gaps and no duplicates.
- Parallel credit application against one balance: never spends more than exists.

**Security and isolation**

- Two sequential requests from different tenants under Octane share no state.
- Every admin screen: tenant A cannot read or mutate tenant B's rows.
- An API key secret never appears in the database, in a log line, or in a response
  after creation.
- SSRF guard: private address, loopback, link-local, metadata address, a redirect
  to any of those, and DNS rebinding between resolution and connection.

## Thresholds

Line coverage ≥ 85% over `src/`, ≥ 90% over `Domain`. Infection MSI ≥ 85 and
covered MSI ≥ 90 on `Shared/Domain`, `Usage/Domain`, `Billing/Domain`,
`Invoicing/Domain`.

The coverage gate measures `src/` and not `app/`. That is not an exemption:
the architecture rules push every business rule into a module, so `app/`
holds service providers and the console kernel and nothing else. If `app/`
ever grows something a coverage number should defend, that something is in
the wrong directory.

Mutation testing arms itself with the first unit test. Until a module has
both domain logic and tests over it there is nothing to mutate, and a job
that fails on an empty tree teaches nobody anything.

Mutation testing runs only on domain code on purpose. Against infrastructure it
measures how thoroughly the mocks are asserted, which is not a useful number.

A threshold is never lowered to make a build pass. If one genuinely has to move,
it moves in its own commit with an ADR explaining what changed about the risk.

## What is deliberately not tested

- Third-party library behaviour. If `brick/money` rounds wrongly, that is a bug
  report, not a test here.
- Filament's own rendering. The tests assert that the right handler was called
  with the right command, and that the tenant scope held.
- Exact Grafana dashboard contents. They are reviewed by eye and screenshotted.
