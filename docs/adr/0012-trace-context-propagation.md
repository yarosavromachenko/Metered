# 0012. Trace context across asynchronous hops

- **Status:** Accepted
- **Date:** 2026-05-30, accepted 2026-09-21

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
| Inbound HTTP | `TraceRequest` extracts `traceparent`, or starts a root span named after the route |
| Into the Redis stream | `traceparent` written as a field of each stream message |
| Stream into the batch consumer | The `usage.batch` span carries **links** to the requests' traces, at most 128 |
| Into the outbox | `traceparent` stored in the message's `headers` column |
| Outbox into a queue job | The relay's `outbox publish` span continues the stored context; `Queue::createPayloadUsing()` puts it in the job payload and a listener restores it |
| Into a webhook delivery | The delivery row keeps the context; `webhooks:dispatch` restores it when it queues the attempt |
| Outbound webhook | The `POST` client span sends `traceparent` to the receiver |
| Period close | Each `billing:close-periods` run is the root span of the closes it performs |

Every span is recorded; nothing is sampled away. Log lines written inside a
span carry `trace_id` and `span_id`.

PHP has no background thread, so the batch span processor would export inside
whichever request ended a span after its delay. Instead every long-running
process flushes at its idle point — after an Octane response, on each pass of a
queue worker or a daemon — at most once a second, and the tracer provider is
built at boot so one Octane worker keeps one for its life.

## Consequences

Two trace shapes cover the path, because usage is aggregated rather than
forwarded. An ingestion request's trace ends at `XADD`; the consumer's
`usage.batch` span, which times the database write, links back to every
request it processed. A state change — a subscription started, an invoice
finalized — is one trace from the request or the period close through the
outbox, the relay, the queue job and the delivery to the receiver's `POST`.
A slow delivery can be attributed to the hop that actually caused it.

The batch consumer uses links rather than a parent on purpose. A batch of 500
events belongs to many traces; electing one as the parent would draw a tree
that is false. Links state what happened — this batch processed those
requests — and Tempo renders them as the relationship they are. The cost is
that links are less familiar than parent-child, and a reader has to know to
follow them.

Every hop carries extra bytes, and the SDK adds work on the ingestion path. It
is measured rather than assumed: with every span exported, the median p99 of
three runs moved by 0.4 ms, within the spread between runs of one mode
([`benchmarks.md`](../benchmarks.md), run 2). An unreachable collector costs a
process about half a second per failed export, paid after the response.

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

**Sample aggressively in all environments.** Rejected: the end-to-end trace is
one of the things the project exists to show, and a sampled-out trace is not
available when someone goes looking for it. The measured cost of recording
everything did not justify sampling at the demo's rates; a production
deployment with far more traffic would sample at the collector.
