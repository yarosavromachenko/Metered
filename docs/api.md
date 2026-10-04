# HTTP API

Base path `/api/v1`. Authentication is an API key sent as
`Authorization: Bearer mk_<env>_<prefix>_<secret>`. The key identifies the
project, and therefore the tenant — there is no tenant id in any path.

The precise shape of every request and response is in the OpenAPI document,
generated from the code: `make openapi` writes it to `docs/api/openapi.json`, CI
publishes it with every run as the `openapi` artifact, and on the running stack
it is browsable at `/docs/api`. It is not committed, so it cannot fall behind the
code it describes. A contract test sends real requests and checks that the
answers match the schemas the document declares.

This page explains what a schema cannot: what is checked when, what
deduplication promises, what `202` does and does not mean.

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
by a migration, or a request that read the key just before the revocation and
cached it just after. The figure is the cache TTL, it is configuration
(`API_KEY_CACHE_TTL_SECONDS`), and raising it raises the promise.

## Conventions

**Errors are RFC 9457 problem+json.** Every failure, including validation, looks
like this:

```json
{
  "type": "https://metered.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request body did not pass validation.",
  "instance": "/api/v1/usage/events",
  "errors": [
    {"pointer": "/events/3/quantity", "detail": "The events.3.quantity field must be a number."}
  ]
}
```

**Pagination is cursor based.** Offsets over a partitioned event table get slower
the further a client reads, and they skip rows when data arrives mid-scan.
Responses carry `{"data": [...], "next_cursor": "..."}`; the cursor is opaque.
The catalog's lists — meters, customers, plans — are the exception and return
every item in `data`: they hold what people define by hand, and a page of them
is the whole of them.

**Rate limits are per key**, returned as `RateLimit-Limit`, `RateLimit-Remaining`
and `RateLimit-Reset`. Exceeding them gives `429` with `Retry-After`. Per key
rather than per address: tenants share NAT addresses, and one noisy neighbour
must not spend everybody's budget.

**Idempotency** applies to mutating management endpoints. Send
`Idempotency-Key: <uuid>`. Replaying the same key with the same body returns the
stored response with `Idempotent-Replayed: true`; the same key with a different
body is `422`; a key whose first request is still running is `409`. A key is
remembered for 24 hours; the same key after that is a new request. Usage
ingestion does not use this header — each event carries its own `event_id`.

**All timestamps are RFC 3339 in UTC.** Money is `{"amount": 1999, "currency": "EUR"}`
in minor units. Quantities are decimal strings, never JSON numbers, because a
JSON number is a float in most clients.

## Surface

Every endpoint belongs to the module that owns its data.

| Area | Endpoints |
|---|---|
| Usage | `POST /usage/events` (batch of up to 100, returns `202`) · `GET /customers/{reference}/usage?meter&from&to` |
| Meters | `POST /meters` · `GET /meters` |
| Plans | `POST /plans` · `GET /plans` · `POST /plans/{id}/versions` |
| Customers | `POST /customers` · `GET /customers` |
| Subscriptions | `POST /subscriptions` · `POST /subscriptions/{id}/cancel` · `POST /subscriptions/{id}/change-plan` |
| Invoices | `GET /invoices?status&customer_ref&limit` · `GET /invoices/{id}` · `GET /invoices/{id}/pdf` · `POST /invoices/{id}/void` |
| Payments | `POST /invoices/{id}/pay` |
| Webhooks | `POST/GET /webhook-endpoints` · `PATCH/DELETE /webhook-endpoints/{id}` · `POST /webhook-endpoints/{id}/rotate-secret` · `GET /webhook-deliveries?status&endpoint_id&limit` · `POST /webhook-deliveries/{id}/replay` |
| Ops | `GET /health/live` · `GET /health/ready` |

## Health, in detail

Neither needs a key. `GET /health/live` answers `200` while the process is up
and asks nothing else. `GET /health/ready` answers `200` when every check
passes and `503` when any fails, naming each:

```json
{"status": "not_ready", "checks": {"database": {"status": "pass", "detail": "ok"}, "redis": {"status": "fail", "detail": "unreachable"}, "usage_backlog": {"status": "fail", "detail": "unreachable"}}}
```

The checks and what to do when one fails are in [`runbook.md`](runbook.md#health).

## Webhook endpoints, in detail

What is delivered, how it is signed and retried, and how to verify it is in
[`webhooks.md`](webhooks.md). Managing endpoints needs an `admin` key, and every
write takes an `Idempotency-Key`.

```http
POST /api/v1/webhook-endpoints

{"url": "https://hooks.example.com/metered", "events": ["invoice.paid", "invoice.voided"], "description": "Billing sync"}
```

```json
{
  "id": "01a0d9b2-5c1e-7a0b-8d3b-6a2e8c4f1b10",
  "url": "https://hooks.example.com/metered",
  "description": "Billing sync",
  "events": ["invoice.paid", "invoice.voided"],
  "enabled": true,
  "secret": "whsec_…LaSw",
  "breaker": {"state": "closed", "consecutive_failures": 0},
  "created_at": "2026-09-25T12:00:00+00:00",
  "signing_secret": "whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw"
}
```

`signing_secret` appears in this answer and in the answer to `rotate-secret`,
and nowhere else, ever. Store it then. `PATCH` changes only the fields it names
(`url`, `description`, `events`, `enabled`). `DELETE` removes the endpoint and
its delivery log; `"enabled": false` keeps both, and holds its deliveries until
it is enabled again.

`GET /webhook-deliveries` lists deliveries newest first; `?status=dead` is the
usual question. `POST /webhook-deliveries/{id}/replay` sends a `dead` or
`failed` delivery again from its first attempt, with the same body; replaying
anything else is a `422`.

## Invoices, in detail

Invoices are built by the system, one per subscription and period, an hour
after the period ends; the API reads and settles them. Everything here needs an
`admin` key — a `usage:write` key reads no invoice — and both writes take an
`Idempotency-Key`, so a retried `pay` cannot collect twice and a retried `void`
cannot issue a second credit note.

```http
GET /api/v1/invoices/01a0d974-2c1e-7f0a-9d3b-6a2e8c4f1b77
```

```json
{
  "id": "01a0d974-2c1e-7f0a-9d3b-6a2e8c4f1b77",
  "number": "INV-000042",
  "status": "finalized",
  "bill_to": {"reference": "acme-corp", "name": "Acme Corp"},
  "period_start": "2026-08-18T09:00:00+00:00",
  "period_end": "2026-09-18T09:00:00+00:00",
  "lines": [
    {
      "kind": "usage",
      "description": "Usage of api.requests",
      "meter_code": "api.requests",
      "quantity": "70058.000000",
      "amount": {"amount": 35529, "currency": "EUR"},
      "covers_start": "2026-08-18T09:00:00+00:00",
      "covers_end": "2026-09-18T09:00:00+00:00",
      "calculation": [
        "up to 1000: 1000 × 0.01 EUR = 10",
        "over 1000: 69058 × 0.005 EUR = 345.29",
        "total 355.29 EUR, rounded once"
      ]
    }
  ],
  "total": {"amount": 35529, "currency": "EUR"},
  "credit_note": null
}
```

A line's `kind` is `fixed`, `usage` or `late`. A late line bills usage that
reached an earlier period after its invoice was built: `covers_start` and
`covers_end` name that period, not the invoice's own.

`POST /invoices/{id}/pay` collects a finalized invoice through the payment
gateway (a fake one in this system) and answers with the paid invoice. `POST
/invoices/{id}/void` takes `{"reason": "…"}`: a finalized invoice is voided with a
credit note carrying the reason, a draft is discarded. A move the invoice's state
does not allow — paying a paid invoice, voiding a paid one — is a `422` whose
`detail` says so. Another project's invoice is a `404`, the same as one that does
not exist.

## Ingestion, in detail

```http
POST /api/v1/usage/events
Authorization: Bearer mk_test_7f3a1b2c_...
Content-Type: application/json

{
  "events": [
    {
      "event_id": "01J9X2H8M4QK3S0T7V2B9C1D5E",
      "meter_code": "api.requests",
      "customer_ref": "acme-corp",
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

The key needs the `usage:write` scope.

| Field | Rule |
|---|---|
| `event_id` | Required, up to 128 characters. The client's own identifier for this event, and the deduplication key |
| `meter_code` | Required, up to 64 characters. The code the meter was defined with; matched case-insensitively |
| `customer_ref` | Required, up to 128 characters. The reference the customer was registered with |
| `quantity` | Required, a non-negative decimal with at most six decimal places, less than `100000000000000` (10^14, what one stored event holds). A string is preferred (`"2.5"`); a JSON number is accepted and converted before any arithmetic |
| `occurred_at` | Required, RFC 3339. Any offset is accepted and converted to UTC |
| `properties` | Optional object, stored with the event |

`202` means the batch is durably in the stream, not that it is in PostgreSQL.
That distinction is the central trade-off of the ingestion design and is spelled
out in [ADR-0003](adr/0003-redis-streams-ingestion.md).

**Checked before `202`:** the key and its scope, a batch of 1 to 100 events, and
every field's shape. A batch with any invalid event is answered `422`, and none
of its events are accepted:

| Problem type | When |
|---|---|
| `validation-failed` | A field is missing or of the wrong type, or the batch is empty or over 100. Every error is listed, each with a pointer |
| `invalid-event` | A field has the right type but an impossible value, such as a negative quantity. It reports the first such event, with a pointer to it |

**Checked by the consumer, after `202`:** that the meter and the customer exist in
this project, and that `occurred_at` is within the acceptance window. An event
that fails one of these is not written. It is stored in `usage_event_rejections`
with one of these reasons, and the admin panel shows it under *Usage →
Rejections*:

| Reason | Meaning |
|---|---|
| `unknown_meter` | No meter in this project has that code |
| `unknown_customer` | No customer in this project has that reference |
| `too_old` | More than seven days before it arrived. The period it belongs to may already be invoiced |
| `in_the_future` | More than five minutes after it arrived. Clock drift is tolerated, further than that is not |
| `malformed` | The message in the stream could not be read as an event at all |

Events are never silently dropped. There is no API endpoint for reading
rejections; they are read in the panel.

**Deduplication.** `event_id` is unique per project, and resending after a
timeout is safe and recommended. Precisely:

- The same `event_id` with the same `occurred_at` is counted once, forever. The
  database's unique key enforces it.
- The same `event_id` with a **different** `occurred_at` is counted once if the
  resend arrives within seven days of the first. After that the first claim has
  expired and the resend is counted as a new event.

So an `event_id` has to stay stable for a given event, and a corrected
timestamp is not a reason to mint a new one. See
[ADR-0002](adr/0002-partitioning-and-deduplication.md) for why the database
cannot enforce the second case.

**Backpressure.** When more than 500,000 accepted events are waiting to be
written, ingestion answers `503` `ingestion-overloaded` with `Retry-After`
instead of accepting work it cannot drain. A queue that only grows is an outage
with extra steps. The number counts events not yet written. It is not the
stream's length, which includes events already written. The same `503` comes
back when the Redis holding the stream has reached its memory limit; resend
the whole batch after `Retry-After`, and events that had already landed are
counted once.

## Reading usage

```http
GET /api/v1/customers/acme-corp/usage?from=2026-09-01T00:00:00Z&to=2026-10-01T00:00:00Z&meter=api.requests
Authorization: Bearer mk_test_7f3a1b2c_...
```

```json
{
  "customer_ref": "acme-corp",
  "from": "2026-09-01T00:00:00+00:00",
  "to": "2026-10-01T00:00:00+00:00",
  "meters": [
    {"meter_code": "api.requests", "aggregation": "sum", "quantity": "184220.000000", "events": 184220}
  ]
}
```

The key needs the `admin` scope. An ingestion key can report usage but not
read it back, so a key leaked from a client's product reveals nothing about
their customers.

The customer is addressed by the reference the tenant registered, not by an
internal id. `from` and `to` are optional and default to the last 24 hours;
`meter` narrows the answer to one meter. An unknown customer is `404`
`customer-not-found` rather than an empty list, because "no usage" and "no such
customer" are different answers.

The numbers come from the hourly aggregates, so `from` and `to` select the
hours whose start falls inside them. `quantity` is folded the way the meter is:
a sum for `sum` and `count` meters, and the highest hourly peak for `max`
meters. `events` is how many events were folded in. Accepted events that the
consumer has not written yet are not in the numbers. The gap is seconds under
normal load and is what the ingestion widget's stream count shows.

## The catalog and subscriptions

Every endpoint here needs an `admin` key, and every write needs an
`Idempotency-Key`. A retried write returns the stored response instead of
creating a second plan version or starting a second subscription.

```http
POST /api/v1/plans/{plan}/versions
Authorization: Bearer mk_live_...
Idempotency-Key: 5f0c7f0e-6a57-4f1b-9d3e-0c9b1f6e2a11
Content-Type: application/json

{
  "interval": "month",
  "prices": [
    {"model": "flat_fee", "amount": 4900},
    {"model": "per_unit", "meter": "api.requests", "unit_price": "0.002"},
    {"model": "graduated", "meter": "storage.gb", "tiers": [
      {"up_to": "100", "unit_price": "0"},
      {"up_to": null, "unit_price": "0.05"}
    ]}
  ]
}
```

A version is created with all its prices in one request and published unless
`"publish": false` is sent. It takes the project's currency, so prices carry
none: `amount` is minor units, and unit prices are decimal strings with at most
eight places. Tier limits are inclusive, the last tier has `"up_to": null`, and
`volume` takes the same tiers as `graduated`. A price names its meter by code;
a flat fee names none. The whole request is one transaction, so a price the
catalog refuses — a tier table that leaves a quantity unpriced, a second price
on one meter — is `422 rule-violated` and leaves no half-built draft.

A published version never changes; a new price is a new version.

```http
POST /api/v1/subscriptions
{"customer_ref": "cus_4471", "plan_version_id": "0192..."}
```

A subscription starts now, on a published version, and is anchored at that
instant — or at `starts_at`, when the request gives one, which backdates it by
at most 366 days and never into the future. A backdated subscription is billed
as if it had started then: the next period close invoices every period that
has already ended, in order, and usage older than the acceptance window is not
part of them unless it was loaded some other way. Periods run from the anchor,
month or year at a time, clamped to the end of a shorter month.
`POST /subscriptions/{id}/change-plan` with a `plan_version_id` takes effect at the end of the current period — the new phase
in the response says exactly when — and may not change the currency or the
interval. `POST /subscriptions/{id}/cancel` ends it at the end of the current
period, or at once with `{"immediately": true}`.

| Answer | When |
|---|---|
| `404 not-found` | The plan, version, customer or subscription is not in this project — including when it is another project's |
| `409 conflict` | A meter code, customer reference or plan code the project already uses |
| `422 validation-failed` | The body's shape: a missing field, an unknown model, a meter code the project does not have |
| `422 rule-violated` | A catalog rule: an unpublished version, a change of interval, a tier table with a bounded last tier |

