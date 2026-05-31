# 0015. Admin panel on Filament

- **Status:** Proposed
- **Date:** 2026-05-31

## Context

The system was originally scoped as an API with no user interface, driven by
console commands and load scripts. That is defensible for a product and wrong for
this repository: the audience includes people who will judge it in ten minutes,
and a running system they can click through communicates more in two minutes than
the architecture document does in ten.

An interface is also genuinely operational. Rejected events, dead-lettered
deliveries, open circuit breakers and invoice line calculations all need a place
to be looked at.

The risk is well known: an admin panel becomes a second, weaker implementation of
the domain, where forms write directly to tables and skip the rules the API
enforces.

## Decision

Filament for the panel, mounted at `/admin` in the same container as the API,
with a boundary that is enforced rather than intended.

- Resources live in `src/<Module>/Presentation/Filament/`. The panel shell,
  navigation and cross-module dashboards live in `src/Admin/`.
- **Reads** may use that module's own Eloquent models directly. This is the query
  side of CQRS; wrapping a table query in a hand-written port would add
  indirection and no safety.
- **Writes always go through an application command handler.** No `->save()`,
  `::create()`, `->update()` or `->delete()` in any presentation class. An
  architecture test enforces this.
- Reaching another module's models from a resource is forbidden; cross-module
  data comes through that module's contract.
- Every screen is tenant-scoped from the session, with a test proving isolation.

## Consequences

The panel exists early and grows with each milestone, so the project has
something to show from M2 onward instead of at the end.

There is exactly one implementation of every business rule. Finalizing an invoice
from the UI runs the same handler as the API — which writes invoice, ledger and
outbox in one transaction and assigns the number under a lock. A form save would
have produced an invoice invisible to the ledger and never published; that
specific failure is the reason the rule exists.

Filament is a real line on a résumé and a real dependency: it couples the
presentation layer to Eloquent and to its own conventions, and a major upgrade is
work. The coupling is contained in `Presentation`, which is the layer explicitly
allowed to know about the framework.

The read exception is a genuine compromise. A resource touching its module's
Eloquent models means the presentation layer knows about persistence. The
alternative was a read-model port returning a query builder, which leaks Eloquent
into `Application` — strictly worse. The compromise is bounded: same module,
reads only, enforced by a test.

## Alternatives considered

**No admin panel, console commands only.** The original scope. Rejected once the
audience was clear: a reviewer will not run `artisan` to understand the domain.

**Livewire and Blade by hand.** Every line would be original code that a reviewer
can assess, which is worth something in a portfolio. Rejected on cost: two to
three weeks to reach what Filament gives in days, spent on table rendering rather
than on billing logic. Custom pages inside Filament cover the screens that
deserve bespoke work — the invoice breakdown in particular.

**Inertia with Vue or React.** Demonstrates frontend skill this project is not
about, and doubles the surface to maintain.

**Filament with resources in one top-level module.** Simpler wiring, and it
breaks module cohesion: deleting a module would leave its screens behind.
