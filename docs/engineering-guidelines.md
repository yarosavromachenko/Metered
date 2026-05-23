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

Enforcement: [`deptrac.layers.yaml`](../deptrac.layers.yaml) for the layering,
[`deptrac.modules.yaml`](../deptrac.modules.yaml) for the module boundaries, plus
Pest Arch tests for the rules Deptrac cannot express (banned helpers, no `float`
in money paths, no writes from the presentation layer).

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
- Thresholds: line coverage ≥ 85% over `src/` and ≥ 90% over `Domain`; Infection
  MSI ≥ 85 and Covered MSI ≥ 90 on the four `Domain` layers.
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
