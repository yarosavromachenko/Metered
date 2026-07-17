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
| `Demo` | What demo mode changes: self-service sign-up exists, and what it may do | Boots with `APP_DEMO=true` |
| `Architecture` | Layering and module boundaries, banned helpers, no `float` money, no writes from presentation | Nothing |

```bash
make test               # everything, with the coverage threshold
make test-fast          # everything, in parallel, no coverage: the loop between edits
make test-unit          # fast loop while writing domain logic
make test-integration   # needs the containers up
make test-concurrency   # slow; run before pushing
make test-arch          # cheap; run constantly
make mutation           # mutation testing on the four domain layers
make mutation-module MODULE=Billing   # one module's domain layer, on its own
```

`make mutation` reports a single score over all four domain layers, which lets
a weak module pass behind strong ones. A milestone criterion that names a
module is checked with `make mutation-module`, which also takes well under a
minute against several for the whole set. Read its score together with the
paragraph on constants below: at the end of M3, `Billing\Domain` alone scored
73.68%, and every one of its ten uncovered mutations was a constant
declaration whose limit a test already pins.

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

**Nothing outside the suite names the database connection.** PHPUnit's
`<env force="true">` does not win against a variable that is already in the
process environment: Laravel reads `$_SERVER` first, and PHPUnit only rewrites
`putenv()` and `$_ENV`. So neither `compose.yaml` nor the CI workflow defines
`DB_CONNECTION` — `phpunit.xml` alone decides, and it chooses `pgsql_testing`.
Defining it elsewhere has cost two debugging sessions: once the suite migrated
the development database, once tests wrote through one connection while the
relay resolved from the container read through another, so a test's uncommitted
rows were invisible to the code under test. `tests/Integration/TestEnvironmentTest.php`
asserts all three facts — the connection, the database name, and that a
connection resolved by name is the same instance the test writes through — so
either mistake fails immediately instead of a day later.

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

Line coverage ≥ 85% over `src/`, ≥ 90% over `Domain`. Mutation score ≥ 85 on
`Shared/Domain`, `Usage/Domain`, `Billing/Domain` and `Invoicing/Domain`.

Mutation testing runs through Pest rather than Infection. Infection drives
PHPUnit directly, and Pest's tests are not PHPUnit classes — it cannot even
load them. Choosing a well-known tool that does not run is worse than choosing
the one that does.

A handful of mutations are reported as *uncovered* rather than tested: those on
class constant declarations, which carry no line coverage because a constant is
resolved at compile time. Mutating `Quantity::SCALE` would genuinely change
behaviour and the test suite would catch it, but the tool cannot run that
mutation, so it counts against the score. This is worth knowing before reading
a score of 90% as four missing tests.

The coverage gate measures `src/` and not `app/`. That is not an exemption:
the architecture rules push every business rule into a module, so `app/`
holds service providers and the console kernel and nothing else. If `app/`
ever grows something a coverage number should defend, that something is in
the wrong directory.

Mutation testing runs the suite with `--parallel`, in CI and in `make mutation`
alike. Each process gets its own database, but all of them share one Redis, so
anything a test keeps in Redis under a fixed name is shared between processes.
The ingestion stream was, and one process's consumer read another's events and
failed on foreign keys its own database could not satisfy. The test case now
gives each process its own stream, dead-letter stream and consumer group
(`Tests\Support\UsageStream`), and tests read those names from configuration
rather than spelling them. Anything new that a test puts in Redis under a fixed
name needs the same treatment.

Mutation testing arms itself with the first unit test. Until a module has
both domain logic and tests over it there is nothing to mutate, and a job
that fails on an empty tree teaches nobody anything.

Mutation testing runs only on domain code on purpose. Against infrastructure it
measures how thoroughly the mocks are asserted, which is not a useful number.

`Tenancy/Domain` is deliberately not in that list, and it is worth saying why
rather than letting the omission look like an oversight. Measured, it scores
about 84: three of its surviving mutants are equivalent — a `(string)` cast on
a `preg_replace` that never returns null, and two on a fallback that only runs
if the ICU extension is missing, which is a hard requirement of the package —
and ten more are constant declarations the tool cannot execute. Reaching the
floor would mean writing tests against unreachable branches, which is a floor
measuring the tool rather than the tests. The module's rules are covered
directly instead: both bounds of a slug, both bounds of a name, every role's
full permission set, deduplicated scopes staying a list, revocation at and
around its instant.

A threshold is never lowered to make a build pass. If one genuinely has to move,
it moves in its own commit with an ADR explaining what changed about the risk.

## What is deliberately not tested

- Third-party library behaviour. If `brick/money` rounds wrongly, that is a bug
  report, not a test here.
- Filament's own rendering. The tests assert that the right handler was called
  with the right command, and that the tenant scope held.
- Exact Grafana dashboard contents. They are reviewed by eye and screenshotted.
