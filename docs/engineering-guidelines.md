# Engineering guidelines

These are the rules this codebase is held to. Most of them are enforced in CI:
if a rule can be checked by a machine, it is, because a convention nobody
verifies stops being a convention within a month.

## Language and style

- Code, comments, documentation, commit messages and UI copy are in English.
- `declare(strict_types=1);` in every PHP file.
- Classes are `final` by default; value objects are `readonly`.
- Pint, preset `per`. PHPStan (Larastan) at level `max`, **no baseline file** —
  a baseline is a list of problems nobody ever fixes.
- Explicit types everywhere, including generics on collections
  (`@return list<Invoice>`, not `@return array`).
- Names come from the glossary in [`domain.md`](domain.md). A concept that is not
  in the glossary gets added there in the same change.

## Module and layer boundaries

Modules live in `src/<Module>/{Domain,Application,Infrastructure,Presentation}`:
`Shared`, `Tenancy`, `Usage`, `Billing`, `Invoicing`, `Webhooks`, `Admin`,
`Simulation`.

- `Domain` and `Application` must not use Laravel (`Illuminate\*`), facades, or
  helpers (`now()`, `config()`, `env()`, `app()`, `dispatch()`).
- Time is only available through `Psr\Clock\ClockInterface`. Production gets a
  system clock; tests get a mock clock. Nothing calls `now()` directly.
- Money is `Money` (integer minor units plus currency). Quantities and unit
  prices are `BigDecimal`. **`float` is banned** for both — it is the one bug
  class a billing system cannot afford.
- One module reaches another only through `<Module>\Application\Contract\*`
  (interfaces and DTOs) or integration events published through the outbox.
  Another module's `Domain` and `Infrastructure` are private.
- Every tenant-owned table carries `organization_id` and `project_id`.
  Repository methods take the tenant context as an explicit argument, so that
  forgetting it is a compile-time shape error rather than a data leak.
- IDs are UUIDv7. Timestamps are `timestamptz` in UTC.

One named exception: a module's service provider is a **composition root**. Its
job is to know every layer well enough to wire them together, so it is declared
as its own layer in the tooling rather than quietly granted an extra dependency.
The alternative is either a layer violation nobody enforces, or a second place
where wiring lives — both worse than naming the exception.

Enforcement: [`deptrac.layers.yaml`](../deptrac.layers.yaml) for the layering,
[`deptrac.modules.yaml`](../deptrac.modules.yaml) for the module boundaries, plus
Pest Arch tests for the rules Deptrac cannot express (banned helpers, no `float`
in money paths, no writes from the presentation layer).

## Design principles

Complexity must be earned. A pattern is used when it solves a problem this
codebase actually has, and the reason is stated where the next reader will find
it — the pull request, the code review, or an ADR. Ten-minute readability for a
new reader beats theoretical purity. The full reasoning, including what this
costs, is [ADR 0018](adr/0018-design-principles.md).

**SOLID, read in terms of this system.** One use case is one command or query
plus one handler; pure domain services — pricing calculator, period calculator,
ledger poster, webhook signer — stay separate from them. Behaviour is extended
by adding a class, such as a new pricing strategy, not by editing existing
branching logic; composition and interfaces before inheritance. Every
implementation of a port passes the same contract tests as its siblings, so an
in-memory double and a database-backed one are interchangeable in fact and not
just in type. Interfaces are small and role-shaped — `ClockInterface`,
`PaymentGateway`, `InvoiceNumberGenerator`, `WebhookSigner` — and never a god
service. `Domain` and `Application` depend on abstractions; `Infrastructure`
implements them.

**KISS and YAGNI, with a budget.** No abstraction for a future that has not
arrived: generalise on the third real use case, not the first. No base classes
or traits that hide behaviour, and no command bus, event sourcing or container
tricks without an ADR. How much structure a module gets is decided in advance:

- `Usage`, `Billing`, `Invoicing` — full four layers.
- `Tenancy`, `Webhooks` — a thin `Application` layer and fewer abstractions,
  still with no `Illuminate` in `Domain`.
- The read side — a query builder in a dedicated read class is enough; it needs
  no repository and no mapper.

**The approved pattern map.** Strategy for pricing models and aggregation
modes. State for the invoice and subscription lifecycles, the circuit breaker
and webhook delivery status. Adapter, in the anti-corruption sense, for the
payment gateway, Redis Streams, the clock and outbound HTTP. Decorator for
idempotency, rate limiting and caching around handlers and repositories. Chain
of responsibility for the HTTP and job middleware pipelines. Command for
application commands and queued jobs. Observer for domain and integration
events through the outbox. Factory or builder for assembling an invoice with
its lines, or a subscription with its phases. Null object for test doubles and
safe defaults. Facade for a module's published contract. Beyond the classic
catalogue: repository, specification if the rules ever grow to need one,
transactional outbox and inbox, and the double-entry ledger. Anything not on
this list needs an ADR, or at minimum a paragraph in the pull request.

**What a machine checks.** [`tests/Architecture/DesignPrinciplesTest.php`](../tests/Architecture/DesignPrinciplesTest.php)
enforces that every concrete class is `final`, that no `Domain` class extends a
concrete parent, that a `*Repository` port is declared in `Domain` and
implemented in `Infrastructure`, that no interface exceeds five methods, and
that a `*Handler` exposes exactly one entry point. Deptrac covers the rest.

**What a machine cannot.** Whether an abstraction has a present-day need,
whether a pattern is the right one, whether the thing could be simpler without
losing an invariant. Those are checklist items in the pull request template, to
be answered in words.

## Correctness guarantees

- A state change that emits an event **must** write to `outbox_messages` in the
  same database transaction. `DB::afterCommit()` is a latency optimisation, never
  a delivery guarantee.
- Consumers must be idempotent, through the inbox table or natural idempotency.
- **Correctness lives in the database.** Uniqueness, check constraints and
  foreign keys are the guarantee; locks, `WithoutOverlapping` and cache checks are
  optimisations layered on top. If removing the lock breaks correctness, the
  constraint was missing.

## Admin panel

The Filament panel is a presentation concern and stays one:

- Resources live in `src/<Module>/Presentation/Filament/`. The panel shell,
  navigation and cross-module dashboards live in `src/Admin/`.
- A resource may read its **own** module's Eloquent models directly — that is the
  query side of CQRS. It must never touch another module's models.
- **Every write goes through an application command handler.** No `->save()`,
  `::create()`, `->update()` or `->delete()` in a presentation class; an arch
  test enforces it. Filament actions call handlers.
- Every screen is tenant-scoped, and a test proving tenant A cannot see or mutate
  tenant B's data is part of the definition of done for that screen.

See [`admin-ui.md`](admin-ui.md) for the panel structure.

## Testing

- Domain logic is written test-first, with table-driven cases for pricing and
  billing-period arithmetic.
- Concurrency tests use real, separate database connections. A concurrency test
  wrapped in `RefreshDatabase` proves nothing, because it never leaves one
  transaction.
- Time-dependent tests use a mock clock. Never `sleep()` to wait for time.
- Integration tests run against real PostgreSQL and Redis, not fakes.
- Thresholds: line coverage ≥ 85% over `src/` and ≥ 90% over `Domain`; mutation
  score ≥ 85 on the four `Domain` layers.
- Thresholds are never lowered to make a build pass. If one has to move, it moves
  in its own commit, with an ADR explaining why.

Details and commands: [`testing.md`](testing.md).

## Security

- Never log secrets, API keys, webhook secrets, or payloads containing personal
  data.
- API key secrets are stored as SHA-256 hashes with a lookup prefix; the secret
  is shown exactly once, at creation.
- Outbound HTTP to customer-controlled URLs goes through the SSRF guard. No other
  code path may call a customer URL directly.
- Demo mode (`APP_DEMO=true`) opens self-service registration and must never run
  alongside real credentials. Its limits are described in
  [`adr/0016-demo-mode-and-seed-profiles.md`](adr/0016-demo-mode-and-seed-profiles.md).

## Working agreement

1. Read the milestone in [`roadmap.md`](roadmap.md) and its acceptance criteria
   before writing code.
2. Small, atomic commits with Conventional Commits messages.
3. `make check` must be green before anything is pushed. A gate that fails for a
   bad reason is fixed in its own commit — never weakened.
4. A new dependency is justified in the pull request: what it replaces, what it
   costs, why hand-writing it is worse.
5. Any significant technical decision becomes an ADR in [`adr/`](adr/), with the
   rejected alternatives written down.
6. Documentation is updated in the same pull request as the code.
