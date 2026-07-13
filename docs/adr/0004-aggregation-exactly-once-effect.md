# 0004. Aggregation with an exactly-once effect

- **Status:** Accepted
- **Date:** 2026-05-29

## Context

Invoices are built from `usage_aggregates`, not from raw events — scanning
millions of rows at close time would be both slow and unpredictable. That makes
the aggregate a derived number that must be exactly right, because it is what a
customer is charged on.

Delivery from a Redis Stream is at-least-once. A consumer that writes to
PostgreSQL, then crashes before `XACK`, will see the same batch again. If the
aggregate update simply adds quantities, the redelivered batch double-counts, and
the customer is overcharged — the worst failure this system has.

## Decision

Insert and aggregate in one transaction, and let the insert decide what counts:

```sql
INSERT INTO usage_events (...)
VALUES (...)
ON CONFLICT (project_id, event_id, occurred_at) DO NOTHING
RETURNING event_id, customer_id, meter_id, occurred_at, quantity;
```

Only the rows actually returned are folded into `usage_aggregates`:

```sql
INSERT INTO usage_aggregates (...) VALUES (...)
ON CONFLICT (project_id, customer_id, meter_id, bucket_start)
DO UPDATE SET quantity = usage_aggregates.quantity + excluded.quantity;
-- max meters use GREATEST(usage_aggregates.quantity, excluded.quantity)
```

`XACK` happens after the transaction commits.

`usage:reconcile` recomputes aggregates from raw events for a period and reports
any drift. It runs in tests, after every chaos scenario, and on demand.

## Consequences

Redelivery is harmless: a duplicate insert returns no row, so it contributes
nothing to the aggregate. The effect is exactly-once even though the delivery is
not — which is the only kind of exactly-once that actually exists.

The aggregation functions must be commutative, and they are: `sum`, `count` and
`max` do not care about ordering, so out-of-order stream delivery is fine. Adding
an order-dependent aggregation later would break this property, and that is a
deliberate constraint on the meter types offered.

A crash between commit and `XACK` causes a redelivery that inserts nothing. Work
is repeated; nothing is double-counted.

The cost is that the insert must return its rows, so `RETURNING` on a large batch
carries a small overhead compared to a blind insert — paid knowingly.

`usage:reconcile` needs raw events to exist for the period it checks, which sets
a floor on retention independent of storage cost.

## Alternatives considered

**Aggregate in a separate pass, reading committed events.** Simpler to write, but
the pass needs its own idempotency and watermark handling, and two passes can
disagree in the window between them.

**Deduplicate in the consumer with an in-memory set.** Works within one process
lifetime and fails across restarts and replicas — exactly when it is needed.

**Compute usage from raw events at invoice time, no aggregates.** Removes this
whole problem, and is genuinely simpler. Rejected on cost: a period close would
scan tens of millions of rows per subscription, and close time would grow with
history.

**Store aggregates in Redis.** Fast, and not durable. The number a customer is
billed on belongs in the database that holds the invoice.

## Accepted in M3

Built as decided: one transaction, the insert returns what it actually inserted,
only those rows are folded, and `XACK` comes after the commit. A test lets the
consumer die between the commit and the acknowledgement, redelivers, and finds
the same two events, the same aggregate and no drift.

What the building added:

**The fold is the domain's.** Rows returned by the insert are folded in PHP by
`Aggregation::fold`, the same code the unit tests reason about, and SQL only
merges the result into whatever is already stored: added for `sum` and `count`,
`GREATEST` for `max`. `count` meters were not in the original sketch. They fold
occurrences, not quantities, and they are commutative like the other two.
`usage:reconcile` re-derives the fold in SQL, which means the same rule is written
twice. An integration test folds the same events both ways and compares, so the
two cannot drift apart without a failing test.

**Reconciliation works in whole buckets.** Its first version cut the window at
the instants it was given, and filtered events by `occurred_at` while filtering
aggregates by `bucket_start`. Any window cut mid-hour therefore held part of an
edge bucket's events and none of its aggregate. The default window, the last 24
hours, is cut mid-hour nearly every time, and against a week of data it reported
530 correct buckets as missing. `--repair` then failed on the primary key. The
window is now widened to whole hours before checking or repairing, and the
command reports the hours it actually compared.

**What reconciliation cannot see.** It proves that aggregates equal the events
under them. It cannot prove that every accepted event became a row, because an
event lost before the insert leaves the two in agreement. That was not
hypothetical: ADR-0002 records the deduplication claim that did exactly this
until M3 fixed it. The guard against that failure is a test of the path that
causes it, not the reconciler.
