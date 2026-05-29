# 0003. Redis Streams for ingestion

- **Status:** Proposed
- **Date:** 2026-05-29

## Context

Ingestion is the one endpoint whose latency is a promise to a client's hot path.
A tenant instruments their own product with it, so a slow response there slows
their product. Writing each event straight to PostgreSQL ties that promise to
database write latency, connection pool depth, index maintenance and lock
contention — all of which vary under exactly the load that matters.

Volume is bursty by nature: batch jobs on a tenant's side produce spikes that are
orders of magnitude above the average.

## Decision

The API does the least possible work and hands off:

1. Authenticate the API key (cached, no database round trip on the hot path).
2. Validate the *shape* only — not whether the meter or customer exists.
3. `XADD` the batch to a Redis Stream through a pipeline.
4. Answer `202 Accepted` with `{accepted, request_id}`.

A long-running artisan daemon `usage:consume` — not a queued job — reads the
stream with a consumer group, in batches of 500 with a block timeout, writes to
PostgreSQL, and acknowledges only after the transaction commits. Messages left
idle for more than 60 seconds are reclaimed with `XAUTOCLAIM`; after N failed
deliveries a message goes to a dead-letter stream. `SIGTERM` finishes the current
batch and exits. The stream is trimmed with `MAXLEN ~`.

When stream length or lag crosses a threshold, ingestion answers `503` with
`Retry-After`.

Redis runs with `appendonly yes` and `appendfsync everysec`.

The web tier connects to PostgreSQL through PgBouncer in transaction pooling
mode. The daemons — consumer, outbox relay, scheduler — connect **directly**,
because transaction pooling breaks session-scoped advisory locks, `LISTEN/NOTIFY`
and server-side prepared statements, all of which long-running processes use.

## Consequences

The hot path never touches PostgreSQL, so ingestion latency is Redis latency plus
serialisation. Bursts are absorbed by the stream instead of becoming lock
contention. The consumer batches writes, which is far more efficient than
per-request inserts.

**`202` means "durably in Redis", not "in PostgreSQL".** With `appendfsync
everysec`, a Redis host failure can lose up to about one second of accepted
events. For usage metering that is an acceptable, stated trade-off; for a payment
authorisation it would not be. It is written in the README, the API reference and
here, because the failure mode people resent is the undocumented one.

Validation feedback is asynchronous. A client that sends an unknown meter gets
`202` and finds the rejection later in the API or the admin panel. Rejections are
therefore first-class: stored with a reason, counted as a metric, visible in the
UI.

The daemon is a process to supervise, restart and scale — more operational
surface than a queue worker, in exchange for control over batching and
acknowledgement.

## Alternatives considered

**Write directly to PostgreSQL.** Simplest, and genuinely correct: `201` would
then mean what clients assume it means. Rejected because it couples a tenant's
hot path to database write latency, and bursts turn into contention. Remains the
right choice for a system where losing one second of data is unacceptable.

**Kafka.** The right tool at an order of magnitude more volume, with real
partitioning, retention and replay. Rejected as operationally disproportionate
here: a broker cluster to run for a single-node workload. Redis is already in the
stack for cache and queues.

**Laravel queues over Redis lists.** Rejected: no consumer groups, no
acknowledgement semantics, no reclaim of stuck messages, and batching would have
to be rebuilt by hand. Streams provide precisely those primitives.

**A queued job per event batch instead of a daemon.** Rejected: Horizon would
give supervision for free, but at-least-once retry semantics on top of a stream
duplicates the mechanism, and controlling batch size and ack ordering from inside
a job fights the framework.
