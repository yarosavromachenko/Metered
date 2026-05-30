# 0012. Trace context across asynchronous hops

- **Status:** Proposed
- **Date:** 2026-05-30

## Context

The path from a usage event to a webhook delivery crosses four asynchronous
boundaries: HTTP into a Redis stream, stream into a batch consumer, database into
an outbox relay, relay into a queue worker. Every boundary is a place where trace
context is normally lost, which is why most "we have distributed tracing" systems
show four disconnected traces and no way to relate them.

Without that continuity the most valuable question is unanswerable: this webhook
fired late — where did the time go?

## Decision

OpenTelemetry SDK, OTLP export to a collector, Tempo for traces, Prometheus for
metrics. W3C `traceparent` as the wire format throughout.

| Boundary | Mechanism |
|---|---|
| Inbound HTTP | Extract `traceparent`, or start a root span |
| Into the Redis stream | `traceparent` written as a field of the stream message |
| Stream into the batch consumer | The batch span carries **links** to each message's trace |
| Into the outbox | `traceparent` stored in the message's `headers` column |
| Outbox into a queue job | `Queue::createPayloadUsing()` injects it; job middleware restores it |
| Outbound webhook | `traceparent` sent to the receiver |

Log lines carry `trace_id` and `span_id`.

## Consequences

One trace spans HTTP → stream → consumer → database → outbox → queue → webhook.
A slow delivery can be attributed to the hop that actually caused it.

The batch consumer uses links rather than a parent on purpose. A batch of 500
events belongs to 500 traces; electing one as the parent would draw a tree that
is false. Links state what happened — this batch processed those events — and
Tempo renders them as the relationship they are. The cost is that links are less
familiar than parent-child, and a reader has to know to follow them.

Every hop carries extra bytes, and the SDK adds overhead on the ingestion path.
It is measured as part of the benchmark rather than assumed to be negligible, and
tracing is sampled in load tests.

Propagating `traceparent` outbound means a receiver can join their trace to ours,
which is a genuine feature for an integrator.

## Alternatives considered

**Log correlation IDs only.** Much cheaper and sufficient for grep-based
debugging. Rejected: it gives no timing structure, which is the thing worth
having across four async hops.

**Parent-child spans for the batch consumer.** Simpler to read and wrong, for the
reason above.

**Vendor-specific propagation.** Rejected: W3C `traceparent` is the standard, and
a receiver-facing header should be one they already understand.

**Sample aggressively in all environments.** Rejected for the demo: the
end-to-end trace is one of the things the project exists to show, and a sampled-
out trace is not available when someone goes looking for it. Sampling applies to
load runs only.
