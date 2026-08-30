# Assumptions

Decisions taken without a specification to point at. They are listed here so they
can be confirmed or corrected deliberately, rather than discovered later in the
code.

## Resolved

| # | Question | Decision | Recorded in |
|---|---|---|---|
| 1 | Project name and namespace | **Metered**, PHP namespace `Metered\`, package `metered/metered`. The API key prefix (`mk_`) and the signature header (`X-Metered-Signature`) follow from it | Everywhere |
| 2 | License | **MIT**, © Yaroslav Romachenko | `LICENSE` |
| 3 | Currency | **One currency per project**, declared at creation. Invoices are always single-currency and nothing converts. Two projects of one organization may differ, so cross-project totals are grouped by currency, never summed | [ADR-0007](adr/0007-money-and-decimals.md) |
| 4 | Admin roles | **Four:** `viewer`, `admin` (catalog and webhook operations), `billing_operator` (the actions that move money), `owner`. `admin` and `billing_operator` are not nested | [ADR-0017](adr/0017-admin-authentication.md) |
| 5 | Demo tenant lifetime | Deleted **7 days after the last sign-in**, by a scheduled command with a mock-clock test | [ADR-0016](adr/0016-demo-mode-and-seed-profiles.md) |
| 6 | Event acceptance window | **7 days in the past, 5 minutes in the future.** The Redis deduplication TTL matches the past window exactly — a shorter TTL would open a gap in the guarantee | [ADR-0002](adr/0002-partitioning-and-deduplication.md) |
| 7 | Timezones in the panel | **UTC only**, labelled as such. Storage and all domain arithmetic are UTC regardless | [ADR-0009](adr/0009-clock-injection.md) |
| 8 | Hosted demo | **No.** Docker Compose on the reviewer's machine | [ADR-0016](adr/0016-demo-mode-and-seed-profiles.md) |
| 9 | PgBouncer and session features | Web tier pooled; daemons connect to PostgreSQL directly | [ADR-0003](adr/0003-redis-streams-ingestion.md) |
| 10 | Row-level security | **Slipped, as allowed.** Isolation is row scoping in the repositories, the panel scope and the schema's composite foreign keys; PostgreSQL RLS as defence in depth is listed under "not implemented" in the README | [ADR-0013](adr/0013-multi-tenancy.md) |
| 11 | Proration on immediate plan change | Stretch inside M5, not a release blocker | [roadmap](roadmap.md) |
| 12 | Admin panel framework | Filament, with the read/write boundary spelled out | [ADR-0015](adr/0015-admin-ui-filament.md) |
| 13 | Panel tenant scope | **Session, both halves.** Filament's built-in tenancy models one tenant; this system scopes by organization *and* project, so using it would split the scope between a URL segment and the session | [ADR-0013](adr/0013-multi-tenancy.md) |
| 14 | Managing members | **Read-only in M2.** Roles exist and are enforced everywhere; the screen that changes them, and invitations, are not built. Demo sign-up makes its visitor the owner, and `org:create` needs no members at all | [ADR-0017](adr/0017-admin-authentication.md) |
| 15 | Sign-up form coverage | The **handler** is tested directly, and the page's presence is tested in both demo and non-demo boots. The three lines that map form fields to the command are not driven through Livewire | [`docs/testing.md`](testing.md) |
| 16 | A catalog before ingestion | **A minimal slice of M4 was built in M3:** meters (code, name, aggregation) and customers (reference, name), defined through handlers and shown in the panel. Events are keyed by meter and customer id, and an event cannot be resolved to ids until those exist. Plans, prices, subscriptions and the management API followed in M4 | [ADR-0003](adr/0003-redis-streams-ingestion.md) |
| 17 | Raw event retention | **400 days, and opt-in.** `usage:partitions:ensure --prune` drops partitions older than that, and the schedule does not pass it. Reconciliation and invoice disputes are checked against raw events, so deleting them is an operator's decision | [ADR-0002](adr/0002-partitioning-and-deduplication.md) |
| 18 | Explorer filtered by a quiet meter | **Left slow, on purpose, until the `heavy` profile says otherwise.** The page walks events newest first and discards other meters. The index that would fix it, `(project_id, meter_id, occurred_at)`, would be the fourth on the largest table | [`query-plans.md`](query-plans.md) |
| 19 | OpenAPI document | **Generated, not committed.** `dedoc/scramble` (a development dependency) builds it from routes, validation rules and responses; CI publishes it as an artifact. Errors are described by a document transformer, because the generator would otherwise describe Laravel's default validation shape and miss the middleware's 401/403/429. A contract test holds real responses to the declared schemas | [`api.md`](api.md) |
| 20 | Ingestion p99 | **The 150ms threshold stands and the baseline fails it** (570ms). It is not lowered without a cause, and naming the cause needs the request tracing that M8 builds | [`benchmarks.md`](benchmarks.md) |
| 21 | When a plan version locks | **At publication, not at first use.** The glossary says a version in use is immutable; locking it when it is published is stronger and has no window in which a version is subscribed to but still editable. Only a published version can be subscribed to, and a price change is a new version | [`domain.md`](domain.md) |
| 22 | Prices per meter | **One price per meter within a version.** Two prices on one meter would charge the same usage twice, which is always a mistake in the catalog rather than a pricing strategy; a charge that needs a base fee and a usage rate is a flat fee plus a usage price | [`domain.md`](domain.md) |
| 23 | When a plan change takes effect | **At the end of the current period, on the same currency and interval.** Every period is then billed on exactly one version and nothing needs prorating; changing the currency or the interval would change what a period is, so it takes a new subscription. One change may wait at a time, and a cancellation at period end drops a change that would have started then. Immediate change with proration stays M5's stretch goal | [`domain.md`](domain.md) |
| 24 | Which period usage belongs to | **The one its hourly bucket starts in.** Invoices read the hourly aggregates, and a bucket is billed on the period whose `[start, end)` contains its start. A subscription anchored at 10:30 therefore does not bill the 10:00 bucket — up to an hour of usage before the anchor's hour ends — and a cancellation at 10:30 bills the whole 10:00 bucket. Consecutive periods share no bucket and miss none, with no rounding in the query | [ADR-0010](adr/0010-period-close-and-invoice-numbering.md) |
| 25 | A late line under a volume discount | **Never negative.** A late line bills the earlier period priced at what it holds now, minus what was billed. Under volume pricing more usage can cost less; the line then bills zero and records the quantity. Paying money back is a credit note, which a person decides to issue | [`domain.md`](domain.md) |
| 26 | Late usage after a subscription's last invoice | **Not billed.** A late line rides on the next invoice, and an ended subscription has none. Usage that reaches its final period after the final invoice is built stays in the aggregates and in `usage:reconcile`, visible, unbilled. The period close also stops looking at a subscription a month after it ended (`INVOICING_ENDED_LOOKBACK_SECONDS`) | [ADR-0010](adr/0010-period-close-and-invoice-numbering.md) |
| 27 | Prepaid credit | **Not in v1.** The `customer_credit` account of ADR-0008 and the posting that applies it need a way to buy credit, which nothing in the system sells. The ledger has three accounts: receivables, revenue and cash | [ADR-0008](adr/0008-double-entry-ledger.md) |
| 28 | Uncollectible invoices | **Not in v1.** Writing an invoice off needs a bad-debt account and a policy for when to do it; a finalized invoice is paid or voided | [`domain.md`](domain.md) |
| 29 | Who may move money | **An owner or a billing operator in the panel, and an admin key through the API.** An API key acts as a system caller; the key's `admin` scope is what admits it to invoices at all, and a key that reports usage reads no invoice | [`api.md`](api.md) |
| 30 | Draft invoices | **Finalized straight away by the period close.** A draft persists only if its finalization failed, and then waits in the panel to be finalized or discarded by hand | [ADR-0010](adr/0010-period-close-and-invoice-numbering.md) |
| 31 | Ending a secret rotation | **The old secret expires by itself after a day.** No second call finishes a rotation: the new secret signs from the moment it is issued, the old one alongside it until the grace period ends. A receiver that has not switched within a day starts rejecting deliveries, which the attempt log shows | [`webhooks.md`](webhooks.md) |
| 32 | `usage.threshold_reached` | **Not in v1.** It needs thresholds configured per customer and meter, and the roadmap names it the second thing to cut; five events are delivered | [`webhooks.md`](webhooks.md) |
| 33 | What opens a breaker | **Five failures in a row that say the receiver is down, for five minutes.** Timeouts, refused connections, `5xx`, `408` and `429` count; a `4xx` refusal means the receiver is up and resets the count. A delivery waiting on an open breaker does not spend an attempt | [ADR-0011](adr/0011-webhook-delivery.md) |
| 34 | Removing an endpoint | **Takes its deliveries and their log with it.** Switching it off keeps both, and its deliveries wait until it is switched back on | [`api.md`](api.md) |
| 35 | Who may manage webhooks | **Admins and owners**, who hold the authority to operate webhooks; a billing operator does not. Through the API, an admin key | [ADR-0017](adr/0017-admin-authentication.md) |
| 36 | Webhooks in the demo | **One trusted destination, the demo's own receiver.** `WEBHOOKS_TRUSTED_DESTINATION` names exactly one `host:port` the SSRF guard lets through on the private network; local and demo only, and the application refuses to boot with it anywhere else. Plain http is accepted in the same two environments | [ADR-0011](adr/0011-webhook-delivery.md) |
| 37 | Where demo mode may run | **`local`, `demo` and `testing`.** Anywhere else `APP_DEMO=true` stops the application from booting. "Real credentials" in ADR-0016 is read as "an environment that is not a demo": there is no real payment provider to detect, and the environment is the one signal every installation sets. `testing` is included because the suite is where demo mode is proven | [ADR-0016](adr/0016-demo-mode-and-seed-profiles.md) |
| 38 | Which tenants a purge may delete | **Only organizations created as demos**, a flag written once and held fixed by a trigger. The invoicing tables' append-only triggers admit a delete only inside a transaction that has declared the organization it is purging, and only when that organization is a demo — so a real tenant's money history cannot be purged even by code that tries | [ADR-0008](adr/0008-double-entry-ledger.md), [ADR-0016](adr/0016-demo-mode-and-seed-profiles.md) |
| 39 | When a demo tenant is idle | **Seven days since any member last signed in**, counting a member who never signed in from when they registered, and never before the organization is seven days old. `tenancy:purge-idle-demos` runs daily; `DEMO_IDLE_DAYS` changes the period | [ADR-0016](adr/0016-demo-mode-and-seed-profiles.md) |
| 40 | What a purge leaves behind | **The audit log's entries about the tenant.** It is a hash chain, and removing an entry breaks every link after it; the purge itself is audited against what it recorded. Accounts that belonged to no other organization are deleted; outbox messages already published are left to the relay's own retention | [ADR-0014](adr/0014-audit-log-hash-chain.md) |
| 41 | Backdated subscriptions | **`starts_at` on `POST /subscriptions`, up to 366 days back, never ahead.** Moving a customer over from another system with their anchor intact is an ordinary billing need, and it is also how the seeded demo gets months of real invoices: backdated subscriptions, history loaded by `sim:backfill`, then the ordinary period close — the invoices are built by the same code as every other. Audited at the moment it was done, with the anchor in the payload | [ADR-0016](adr/0016-demo-mode-and-seed-profiles.md) |

## Consequences worth remembering

**Currency per project** means no dashboard may sum across projects without
grouping. A single figure combining EUR and USD is worse than no figure, so the
widgets group and the tests assert it.

**`admin` and `billing_operator` are disjoint on writes.** They overlap on reads
only. A policy that accidentally grants everything to everyone therefore fails a
test rather than passing unnoticed — which is the reason the split is worth
having in a project this size.

**Revocation is bounded, not instant.** Authentication reads a cached key, the
write that revokes drops the entry, and the cache TTL is what holds if that
invalidation never arrives. Thirty seconds is the published figure, and it is
the same number in `config/metered.php`, in `docs/api.md` and in the test that
moves the clock past it.

**The deduplication TTL is coupled to the acceptance window.** Changing one
without the other opens a hole in the deduplication guarantee. Both are
configuration, and `UsageConfigurationTest` asserts the TTL covers the window.
The same test holds the backpressure threshold to at most half the stream's
trim length, the other pair of settings that are only correct together.

## Pinned at M0, not before

Exact versions of PHP, Laravel, PostgreSQL, Redis and Filament are pinned when
the skeleton is generated, from what is stable at that moment, and recorded in
the README. Writing version numbers into documentation before running
`composer create-project` produces documentation that is wrong on day one.
