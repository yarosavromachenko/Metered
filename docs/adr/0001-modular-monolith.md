# 0001. Modular monolith with machine-enforced boundaries

- **Status:** Proposed
- **Date:** 2026-05-29

## Context

The system has clearly separable concerns — metering, pricing, invoicing,
delivery — which is exactly the shape that invites splitting into services. But
the correctness requirements pull the other way. Finalizing an invoice must write
the invoice, its lines, the ledger entries and the outbox message atomically, and
assign a gapless number under a row lock. Across services that becomes a saga
with compensations, and every compensation is a new way to produce a wrong
number in someone's accounting.

The opposite failure is just as real: a single Laravel application where any
class may reach any other becomes an unsplittable ball of mud within months, and
the "modules" in its folder names mean nothing.

## Decision

One deployable, organised as modules with layers, and boundaries enforced by
tooling rather than by discipline.

- `src/<Module>/{Domain,Application,Infrastructure,Presentation}`.
- `Domain` depends on nothing but a short allow-list of value libraries.
  `Application` depends on `Domain`. Dependencies point inward.
- A module reaches another only through `<Module>\Application\Contract\*` or
  integration events published through the outbox.
- Deptrac checks both rules on every pull request, with Laravel declared as its
  own layer so "the domain must not see the framework" is verifiable.
  Pest Arch covers what Deptrac cannot express: banned helpers, `float` in money
  paths, writes from the presentation layer.

## Consequences

Transactional correctness stays cheap: the invariants that matter are enforced by
database constraints inside one transaction.

Extraction stays possible. A module already talks to its neighbours through a
contract and events, so replacing an in-process contract with an HTTP client is
localised work rather than a redesign.

The cost is friction. Reaching into another module's models to get one field is
forbidden even when it would obviously work, and cross-module reads sometimes
require adding a contract method. That friction is the point, but it is felt
weekly.

A second cost: the layer rules mean writing mappers between Eloquent rows and
domain objects instead of using Eloquent models as the domain. That is more code
and the most common objection to this style.

## Alternatives considered

**Microservices from the start.** Rejected: distributed transactions for invoice
and ledger, operational overhead far beyond one developer, and none of the
scaling pressure that would justify it. Extraction remains available later.

**Plain Laravel, no modules.** Rejected: nothing would stop an invoice controller
from querying usage events directly, and within months the boundaries would exist
only in documentation.

**Modules by folder convention, no tooling.** Rejected: an unchecked convention
is a convention until the first deadline.

**Eloquent models as the domain model.** Tempting, and much less code. Rejected
because the pricing and ledger logic is the part of this system worth getting
right, and it is far easier to test as pure objects with no database and no
clock.
