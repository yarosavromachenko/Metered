# 0007. Money and decimal arithmetic

- **Status:** Proposed
- **Date:** 2026-05-30

## Context

This is a billing system. The arithmetic it performs is the product. Floating
point cannot represent `0.1` exactly, so a chain of float operations across
quantities, tiers and totals accumulates error that eventually shows up as an
invoice off by a cent — the kind of bug that destroys trust in every other number
the system produces.

Usage quantities are not integers either. Gigabyte-hours, fractional API credits
and per-second billing all produce decimals, and rounding them early loses money
in one direction or another.

## Decision

Money is a `Money` value object wrapping integer minor units plus an ISO 4217
currency, backed by `brick/money`. It is stored as `bigint` plus `char(3)`.
Money of different currencies cannot be added; the type refuses.

Quantities and unit prices are `BigDecimal` (`brick/math`), stored as
`numeric(20,6)` for quantities and `numeric(20,8)` for unit prices. The extra
precision on prices exists because per-unit prices are routinely fractions of a
cent.

`float` is banned in money and quantity paths, enforced by an architecture test
rather than by review.

Rounding happens **once per invoice line**, mode `HALF_UP`, after all tier
arithmetic. Line totals are then summed as integers, so the invoice total is
exactly the sum of what is printed.

## Consequences

The number on the invoice is the number that was computed. Reproducing a charge
means re-running a pure function, not approximating it.

Rounding once, at the line, is a decision with visible effects: rounding each
tier separately would give a slightly different total, and rounding only at the
invoice level would make the printed lines fail to add up. The chosen point is
the one that keeps the document internally consistent, and it is documented for
anyone reconciling against it.

The cost is verbosity. `$a->plus($b)` instead of `$a + $b`, and conversions at
every boundary — JSON, database, PDF. Quantities cross the API as decimal
strings, never JSON numbers, because a JSON number is a double in most clients
and the precision would be lost on the way in.

`numeric` arithmetic in PostgreSQL is slower than `bigint`, which matters on the
aggregate upsert path. Accepted: correctness at that particular point is not
negotiable.

## Alternatives considered

**Float, with rounding at the end.** Rejected on principle and on experience;
this is the single most common source of billing bugs.

**Integer minor units for everything, including quantities.** Works for money,
breaks for usage — "0.000001 units" has no minor unit, and inventing one is
choosing a precision limit up front.

**Laravel's decimal casts only.** They control storage, not arithmetic. The
computation in between would still be float.

**A hand-rolled money class.** Half a day to write, and it would slowly grow
allocation, distribution and rounding-mode handling until it was a worse
`brick/money`.
