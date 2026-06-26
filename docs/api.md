# HTTP API

Base path `/api/v1`. Authentication is an API key sent as
`Authorization: Bearer mk_<env>_<prefix>_<secret>`. The key identifies the
project, and therefore the tenant — there is no tenant id in any path.

The generated OpenAPI document is the precise reference; this page explains the
shape and the rules behind it. Regenerate with `make openapi`.

## Authentication

A token looks like `mk_test_7f3a1b2c_<48 hex characters>`: a scheme, the
environment, an eight character prefix and the secret itself. The environment is
readable at a glance, so a test key pasted into a production configuration file
is refused by its shape rather than by writing test traffic into live billing
data.

Only the prefix and a SHA-256 hash of the whole token are stored. The secret is
shown once — by `org:create`, by the panel when a key is issued, by the sign-up
that creates the first one — and exists nowhere afterwards. A lost key is
replaced, not recovered.

Keys carry scopes. `usage:write` reports usage events; `admin` manages the
catalog over the API. They do not nest: a management key does not gain the
ability to write billable events by implication. A route that needs a scope the
key does not carry answers `403` with
`https://metered.dev/problems/insufficient-scope`.

| Situation | Answer |
|---|---|
| No `Authorization` header | `401` `invalid-api-key`, with `WWW-Authenticate: Bearer` |
| A token that is not ours, or an unknown prefix, or a wrong secret | `401` `invalid-api-key` |
| A key that was revoked | `401` `revoked-api-key` |
| A valid key without the scope the route needs | `403` `insufficient-scope` |

An unknown prefix and a wrong secret deliberately answer the same thing.
Telling them apart would turn the endpoint into an oracle for which prefixes
exist.

**Revocation takes effect within 30 seconds.** Authentication reads a cached
lookup, and revoking drops that entry, so in practice a revoked key stops
working immediately. The thirty seconds is the bound that holds when the
invalidation does not arrive — another node, a local cache store, a row changed
by a migration. The figure is the cache TTL, it is configuration
(`API_KEY_CACHE_TTL_SECONDS`), and raising it raises the promise.

## Conventions

**Errors are RFC 9457 problem+json.** Every failure, including validation, looks
like this:

```json
{
  "type": "https://metered.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more events were rejected.",
  "instance": "/api/v1/usage/events",
  "request_id": "01J9X2...",
  "errors": [
    {"pointer": "/events/3/quantity", "detail": "must be a non-negative decimal"}
  ]
}
```

**Pagination is cursor based.** Offsets over a partitioned event table get slower
the further a client reads, and they skip rows when data arrives mid-scan.
Responses carry `{"data": [...], "next_cursor": "..."}`; the cursor is opaque.

**Rate limits are per key**, returned as `RateLimit-Limit`, `RateLimit-Remaining`
and `RateLimit-Reset`. Exceeding them gives `429` with `Retry-After`. Per key
rather than per address: tenants share NAT addresses, and one noisy neighbour
must not spend everybody's budget.

**Idempotency** applies to mutating management endpoints. Send
`Idempotency-Key: <uuid>`. Replaying the same key with the same body returns the
stored response with `Idempotent-Replayed: true`; the same key with a different
body is `422`; a key whose first request is still running is `409`. Usage
ingestion does not use this header — each event carries its own `event_id`.

**All timestamps are RFC 3339 in UTC.** Money is `{"amount": 1999, "currency": "EUR"}`
in minor units. Quantities are decimal strings, never JSON numbers, because a
JSON number is a float in most clients.

## Surface

| Area | Endpoints |
|---|---|
| Usage | `POST /usage/events` (batch of up to 100, returns `202`) · `GET /customers/{id}/usage?meter&from&to` |
| Meters | `POST /meters` · `GET /meters` |
| Plans | `POST /plans` · `GET /plans` · `POST /plans/{id}/versions` |
| Customers | `POST /customers` · `GET /customers` |
| Subscriptions | `POST /subscriptions` · `POST /subscriptions/{id}/cancel` · `POST /subscriptions/{id}/change-plan` |
| Invoices | `GET /invoices` · `GET /invoices/{id}` · `GET /invoices/{id}/pdf` · `POST /invoices/{id}/void` |
| Payments | `POST /invoices/{id}/pay` |
| Webhooks | `POST/GET/PATCH/DELETE /webhook-endpoints` · `POST /webhook-endpoints/{id}/rotate-secret` · `GET /webhook-deliveries` · `POST /webhook-deliveries/{id}/replay` |
| Ops | `GET /health/live` · `GET /health/ready` |

## Ingestion, in detail

```http
POST /api/v1/usage/events
Authorization: Bearer mk_test_7f3a_...
Content-Type: application/json

{
  "events": [
    {
      "event_id": "01J9X2H8M4QK3S0T7V2B9C1D5E",
      "meter": "api_requests",
      "customer": "acme-corp",
      "quantity": "1",
      "occurred_at": "2026-08-19T10:15:00Z",
      "properties": {"endpoint": "/v1/search", "region": "eu-central"}
    }
  ]
}
```

```http
HTTP/1.1 202 Accepted

{"accepted": 1, "request_id": "01J9X2H8M4QK3S0T7V2B9C1D5F"}
```

`202` means the batch is durably in the stream, not that it is in PostgreSQL.
That distinction is the central trade-off of the ingestion design and is spelled
out in [ADR-0003](adr/0003-redis-streams-ingestion.md).

What is validated synchronously: authentication, batch size, field shapes, and
that `occurred_at` is within the acceptance window. What is validated in the
consumer: that the meter and customer exist, and that the quantity fits the
meter's aggregation. A failure there lands in `usage_event_rejections` with a
reason and is visible in the admin panel — events are never silently dropped.

`event_id` is the deduplication key, unique per project. Resending after a
timeout is safe and is the recommended client behaviour.

When the stream backs up beyond its threshold, ingestion answers `503` with
`Retry-After` instead of accepting work it cannot drain. A queue that only grows
is an outage with extra steps.
