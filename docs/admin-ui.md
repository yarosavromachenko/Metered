# Admin panel

The panel is a Filament application served by the same container as the API,
under `/admin`. It exists for two reasons: an operator needs to see what the
system is doing, and a reviewer needs to click through the domain instead of
reading about it.

It is a presentation layer and nothing more. There is no business rule behind a
screen that is not also behind the API, because both call the same application
handler.

## Layout

```
src/<Module>/Presentation/Filament/
├── Resources/        one resource per aggregate the module owns
├── Pages/            custom pages for things a CRUD resource cannot express
└── Widgets/          numbers and charts about that module

src/Admin/
├── Presentation/     panel provider, navigation, theme, tenant switcher
└── ...               cross-module dashboards that no single module owns
```

Each module owns its own screens, so deleting a module deletes its UI with it.
`Admin` only composes.

## The two rules

**1. Reads may use the module's own Eloquent models.** A Filament table needs a
query builder, and wrapping one in a hand-written read port buys nothing but
indirection. This is the query side of CQRS and it is allowed — for that module's
models only.

**2. Writes always go through an application command handler.** No `->save()`,
`::create()`, `->update()` or `->delete()` anywhere in a presentation class. A
Filament action builds a command and dispatches it, exactly like the controller
for the equivalent REST endpoint. An architecture test enforces this, because it
is the rule that decays first.

The reason is not purity. Invoice finalization writes the invoice, the ledger
entries and the outbox message in one transaction and assigns a gapless number
under a row lock. A `->save()` from a form would produce an invoice that is
invisible to the ledger and never published. The handler is the only place that
sequence exists.

## Screens

| Module | Screens |
|---|---|
| Tenancy | Organizations, projects, API keys (create shows the secret once), members and roles |
| Usage | Event explorer with filters, rejections with their reason, aggregates, stream lag widget |
| Billing | Meters, plans and versions, prices, customers, subscriptions with their phases |
| Invoicing | Invoice list, invoice detail showing how each line was computed, PDF, void, pay, ledger view |
| Webhooks | Endpoints, delivery log with response codes and durations, manual replay, circuit breaker state |
| Admin | Dashboard: ingestion rate, events today, revenue this period, open invoices, failing endpoints |

The invoice detail page is the one worth building carefully. It shows the period,
the aggregates it read, the price that applied, the tier breakdown, and the
rounding step — the whole path from raw events to an amount owed. That page is
the best argument the repository makes about the correctness of its pricing.

## Tenancy

Filament's tenancy binds the panel to an organization; a project switcher narrows
it further. Every resource query is scoped by both, and the scope comes from the
authenticated session rather than from anything in the request.

Each admin screen ships with a test that signs in as tenant A and asserts that
tenant B's rows are neither listed nor reachable by direct id. That test is part
of the definition of done for the screen, not a follow-up.

## Demo mode

With `APP_DEMO=true` the panel offers self-service sign-up: a visitor gets their
own organization, project, API key and a seeded dataset, isolated from everyone
else's. Limits and lifecycle are described in
[ADR-0016](adr/0016-demo-mode-and-seed-profiles.md).

## Testing

Livewire feature tests assert three things per screen: the right handler was
called with the right command, the tenant scope held, and an unauthorized role
was refused. Filament's own rendering is not re-tested here.
