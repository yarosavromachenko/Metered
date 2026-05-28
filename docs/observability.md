# Observability

The goal is one trace that spans the whole life of an event:

```
HTTP request → Redis Stream → consumer batch → PostgreSQL → outbox → queue job → webhook delivery
```

Getting that trace to survive four asynchronous hops is most of the work, and it
is the part that is usually skipped.

## Propagation

| Hop | How context travels |
|---|---|
| HTTP → application | W3C `traceparent` header, or a new root span |
| Application → Redis Stream | `traceparent` as a field on the stream message |
| Stream → consumer | The batch span carries **links** to each message's trace, not a parent |
| Application → outbox | `traceparent` stored in the message `headers` column |
| Outbox → queue | `Queue::createPayloadUsing()` injects it; job middleware restores it |
| Queue → webhook | Outgoing request carries `traceparent` to the receiver |

The batch consumer uses span links deliberately. A batch of 500 events belongs to
500 different traces, so naming one of them "the parent" would be a lie that
makes the resulting trace tree misleading. Links express what is actually true:
this batch processed those events.

## Metrics

| Metric | Why it is the one worth watching |
|---|---|
| `ingest_requests_total`, `ingest_duration_seconds` | The hot path. Latency here is a client-visible promise. |
| `usage_stream_length`, `usage_stream_pending` | Lag. Rising pending means consumers are behind, which is the earliest sign of trouble. |
| `usage_batch_write_duration_seconds` | Where ingestion capacity is actually spent. |
| `usage_events_rejected_total` | By reason. A spike is a client integration breaking. |
| `outbox_unpublished_age_seconds` | The age of the oldest unpublished row — a far better alarm than a count. |
| `webhook_delivery_duration_seconds`, `webhook_deliveries_total` | By status. Success rate per endpoint. |
| `webhook_breaker_open` | Which endpoints are currently cut off. |
| `invoices_finalized_total`, `billing_close_duration_seconds` | Whether period close keeps up. |
| `dlq_size` | Anything in a dead-letter queue is work someone must decide about. |

Ages beat counts for queue alarms: a backlog of 10,000 rows draining in two
seconds is healthy, while three rows stuck for an hour is an incident.

## Logs

Structured JSON, every line carrying `trace_id`, `span_id`, `project_id` where
known, and `request_id`. Never a secret, an API key, a webhook secret, or a full
payload that may contain personal data.

## Dashboards

Provisioned from the repository under `docker/grafana/`, so a clean clone gets
the same dashboards without clicking:

1. **Ingestion** — request rate, latency percentiles, stream length and pending, rejection reasons.
2. **Processing** — batch size and duration, aggregate upsert rate, partition count, reconciliation drift.
3. **Delivery** — outbox age, queue depth per connection, webhook success rate per endpoint, breaker states, DLQ size.
4. **Billing** — periods closed, invoices finalized, revenue booked, close duration.

Prometheus alert rules live beside them in the repository.

## Screenshots

> To be added in M8: the end-to-end trace in Tempo, and each dashboard under
> load from `sim:traffic`. A claim about observability that ships without a
> picture of the trace is a claim nobody can check.
