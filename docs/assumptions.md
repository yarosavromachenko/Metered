# Assumptions

Decisions taken without explicit confirmation, so that work could continue. Each
one is cheap to reverse now and expensive later; they are listed here to be
confirmed or corrected rather than discovered in the code.

## Open — confirm before M0 ends

| # | Assumption | Cost to change later |
|---|---|---|
| 1 | Project name **Metered**, PHP namespace `Metered\`, composer package `metered/metered` | Low now (one rename across ~10 files), high once there are hundreds |
| 2 | License **MIT**, copyright "Yaroslav Romachenko" | Trivial |
| 3 | Base currency for seeds and examples is **EUR**; a single currency per invoice, with no conversion | Low |
| 4 | Admin roles are `owner`, `admin`, `viewer` — no finer permissions | Medium: adding granularity later means a migration and new policies |
| 5 | Demo tenants are deleted 7 days after their last sign-in | Trivial |
| 6 | The acceptance window for events is 7 days in the past and 5 minutes in the future | Low, it is configuration |
| 7 | Timestamps are stored and returned in UTC only; no per-tenant display timezone | Medium if the admin panel later needs local time |

## Resolved

| # | Question | Decision |
|---|---|---|
| 8 | Hosted demo, or local only? | **Local only.** Docker Compose, no hosted instance to pay for or defend |
| 9 | PgBouncer in transaction mode breaks session features | The web tier is pooled; the daemons connect to PostgreSQL directly |
| 10 | Row-level security as defence in depth? | Stretch in M2. If it slips, it goes to "not implemented" with the reason |
| 11 | Immediate plan change with proration in v1.0.0? | Stretch inside M5, not a release blocker |
| 12 | Admin panel framework | Filament, with the read/write boundary described in ADR-0015 |

## Pinned at M0, not before

Exact versions of PHP, Laravel, PostgreSQL, Redis and Filament are pinned when
the skeleton is generated, from what is stable at that moment, and recorded in
the README. Writing version numbers into documentation before running
`composer create-project` produces documentation that is wrong on day one.
