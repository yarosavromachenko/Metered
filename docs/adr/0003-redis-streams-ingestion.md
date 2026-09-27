# 0003. Redis Streams for ingestion

- **Status:** Accepted
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

## Accepted in M3

The shape is as decided: the endpoint validates shape, appends to the stream in
one pipelined round trip, and answers `202`. `usage:consume` is a daemon that
reads 500 at a time with a two-second block. It acknowledges only after the
commit, reclaims anything idle for a minute, and after five
deliveries sends the message to `usage:events:dead`. On `SIGTERM` it finishes
the batch it has. Redis runs `appendonly yes` with `appendfsync everysec`. The
consumer and the relay connect to PostgreSQL directly, and the web tier goes
through PgBouncer.

What the building added:

**Reclaiming reads the pending list, not `XAUTOCLAIM`.** `XPENDING` carries
each message's delivery count and `XAUTOCLAIM` does not, and the count is how a
poison message is recognised before it is processed a sixth time. So the
consumer lists what has been idle for a minute, sets aside what has been
delivered too often, and claims the rest with `XCLAIM`.

**Existence is checked in the consumer, not the endpoint.** An unknown meter or
customer gets a `202` and a stored rejection with a reason, as this decision
promised. Resolving them needed a catalog to exist first, so a minimal slice of
M4's meters and customers was brought forward ([`assumptions.md`](../assumptions.md)).

**Backpressure is based on the backlog, not the stream's length.** The first
version compared `XLEN` against the threshold. But the stream keeps entries after
they are acknowledged, and only `MAXLEN ~` trimming removes them, so its length
measures history rather than backlog. Under a steady load with an idle consumer,
ingestion started answering `503`. It now measures what is delivered-but-pending
plus what is not yet delivered, and sheds above 500,000.

**One invariant follows from that:** the threshold has to stay well below
`USAGE_STREAM_MAX_LENGTH` (1,000,000). `MAXLEN ~` trims the oldest entries
whether or not they have been written. As long as the backlog cannot get close
to the stream's length, the entries trimmed are ones that were acknowledged long
ago. Both are environment variables and nothing stops them being set apart at
runtime. A test holds the configuration to at most half of the length, so the
shipped values cannot drift apart without it failing.

**Tenants in one read are written independently.** A read holds several
tenants' messages, and each tenant is written in its own transaction. One
tenant's failed write leaves only its own messages pending; the rest of the read
is written and acknowledged, and the failure is raised once they are. Otherwise
one failing tenant would hold every tenant read with it, and after five
deliveries dead-letter them all.

**A project deleted while its events wait is not a failure.** A demo tenant
can be reset or purged with events still in the stream, and nothing is left
for them to belong to — not even a rejection row, whose foreign key needs the
project. The consumer sends them to the dead-letter stream as `project_gone`.

**Dead letters are read and replayed from the console.** `usage:dead-letters`
lists them with their reason; `usage:dead-letters:replay` moves them back to
the stream, atomically, and refuses `malformed` and `project_gone`, which
would only be set aside again. A replayed event keeps its id and timestamp, so
replaying twice writes it once.

**What the baseline showed about latency.** [`benchmarks.md`](../benchmarks.md)
has the run: 2,031 events a second accepted, no errors, the consumer at zero
lag, p50 31.6ms and p95 36.1ms. **The p99 is 570ms against a threshold of 150ms,
so the run fails, and the threshold was left where it is.** The consequence
claimed above, that latency is Redis latency plus serialisation, is the part
that did not hold. The Redis append is about a millisecond. The handler's
slowest pass in 3,243 requests was 21.7ms. The median and the tail are both in
the framework's middleware and in validating a fifty-event body. Redis, the
consumer, Octane worker recycling, PHP's garbage collector and debug mode were
each ruled out by changing only that one thing and measuring again. The request
trace named the cause: tracing, on by mistake, exported inside the request to a
collector that did not exist. Export now happens after the response, and the
re-measured p99 is 37.0ms ([`benchmarks.md`](../benchmarks.md)).
