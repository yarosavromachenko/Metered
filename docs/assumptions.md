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
| 10 | Row-level security | Stretch in M2. If it slips it goes to "not implemented" with the reason | [ADR-0013](adr/0013-multi-tenancy.md) |
| 11 | Proration on immediate plan change | Stretch inside M5, not a release blocker | [roadmap](roadmap.md) |
| 12 | Admin panel framework | Filament, with the read/write boundary spelled out | [ADR-0015](adr/0015-admin-ui-filament.md) |

## Consequences worth remembering

**Currency per project** means no dashboard may sum across projects without
grouping. A single figure combining EUR and USD is worse than no figure, so the
widgets group and the tests assert it.

**`admin` and `billing_operator` are disjoint on writes.** They overlap on reads
only. A policy that accidentally grants everything to everyone therefore fails a
test rather than passing unnoticed — which is the reason the split is worth
having in a project this size.

**The deduplication TTL is coupled to the acceptance window.** Changing one
without the other opens a hole in the deduplication guarantee. Both are
configuration, and the test suite asserts they match.

## Pinned at M0, not before

Exact versions of PHP, Laravel, PostgreSQL, Redis and Filament are pinned when
the skeleton is generated, from what is stable at that moment, and recorded in
the README. Writing version numbers into documentation before running
`composer create-project` produces documentation that is wrong on day one.
