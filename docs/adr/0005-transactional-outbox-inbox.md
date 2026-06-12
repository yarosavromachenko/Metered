# 0005. Transactional outbox and inbox

- **Status:** Accepted
- **Date:** 2026-05-30

## Context

Finalizing an invoice changes state and must tell the outside world about it. The
obvious code dispatches a job right after the write. That code has a hole: the
transaction and the queue are two systems, and there is no atomic step across
them. A crash in between either publishes an event for a change that rolled back,
or commits a change nobody hears about. Under load, both happen.

`DB::afterCommit()` narrows the window. It does not close it — the process can
still die after the commit and before the dispatch.

## Decision

Nothing dispatches from inside business logic. State changes write their events
to `outbox_messages` in the same transaction:

```
id, aggregate_type, aggregate_id, type, payload jsonb,
headers jsonb, occurred_at, published_at, attempts
```

with a partial index on `published_at IS NULL`.

A daemon `outbox:relay` polls with `SELECT ... FOR UPDATE SKIP LOCKED`,
dispatches, and stamps `published_at`. `DB::afterCommit()` may nudge the relay to
publish sooner, but it is never the guarantee.

Consumers deduplicate through `inbox_messages` with `UNIQUE (consumer, message_id)`:
a message already recorded is skipped.

`headers` carries the `traceparent`, so the eventual delivery belongs to the
trace of the request that caused it.

## Consequences

An event is published if and only if its state change committed. That is the
property the whole delivery chain rests on.

Publication is at-least-once — the relay can dispatch and die before stamping —
so consumers must be idempotent. The inbox makes that mechanical instead of a
per-consumer judgement call.

`SKIP LOCKED` means relays can be scaled horizontally without coordination.

The cost is latency: an event is published on the relay's next pass rather than
instantly. With a short poll interval and an in-process nudge this is
milliseconds in practice, but it is not zero, and a synchronous reader of the
outbox table would see a row that has not yet been delivered.

A second cost is a table that grows. Published rows are pruned on a schedule, and
the partial index keeps the poll unaffected by the ones still there.

## Alternatives considered

**Dispatch after commit and accept the risk.** Rejected: the failure is silent,
and the missing event is discovered by the customer whose automation never fired.

**Listen to PostgreSQL logical replication (change data capture).** Genuinely
exactly-once at the source and no application-side table. Rejected: a replication
slot to operate, a schema-coupled decoder to maintain, and a slot that blocks WAL
recycling when its consumer stalls. Far more machinery than one table and a poll.

**`LISTEN/NOTIFY` to wake the relay.** Attractive, and rejected because
PgBouncer's transaction pooling breaks it for the pooled tier. The daemons could
use it over their direct connections; that remains a possible optimisation, not a
foundation.

**Queue the event and make consumers deduplicate, with no outbox.** Solves
double-processing but not the lost event, which is the failure that matters.


## Accepted in M1

Built, and the failure it exists for has its own test: the message is written
inside a transaction, nothing dispatches, and the event still goes out on the
relay's next pass. Three relays publishing forty messages produce forty
publications and no duplicate, which is `SKIP LOCKED` doing its job under real
concurrent processes.

One change the tests forced. The inbox key is a name the handler declares, not
its class name: a consumer key is persisted state, and renaming the class would
have made every message it had already processed look new.
