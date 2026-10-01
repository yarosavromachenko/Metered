# Webhooks

Metered tells a tenant's systems what happened: a subscription was created, an
invoice was finalized, a payment succeeded. Delivery is at-least-once, so every
payload carries an `id` a receiver can deduplicate on.

## Payload

```http
POST /metered HTTP/1.1
Host: hooks.example.com
Content-Type: application/json
User-Agent: Metered-Webhooks/1
X-Metered-Event-Id: 01a0d974-2c1e-7f0a-9d3b-6a2e8c4f1b77
X-Metered-Event-Type: invoice.paid
X-Metered-Signature: t=1790337600,v1=b8bca8b24ab0180f1a9bf6ef35319860ab14523cf6ea8c662728ddc0fe87779c
```

```json
{
  "id": "01a0d974-2c1e-7f0a-9d3b-6a2e8c4f1b77",
  "type": "invoice.paid",
  "created_at": "2026-09-25T12:00:00+00:00",
  "data": {
    "invoice_id": "01a0d974-100d-717b-bc85-99ff33e83a11",
    "customer_id": "01a0cb55-ec0f-70a2-a9e6-0135826fbf8a",
    "subscription_id": "01a0d974-0f9a-7c2e-8e44-5b1d0c2a7e31",
    "number": "INV-000042",
    "status": "paid",
    "total_minor": 45630,
    "currency": "EUR",
    "period_start": "2026-08-18T09:00:00+00:00",
    "period_end": "2026-09-18T09:00:00+00:00",
    "payment_reference": "fake_4150235cb85babe3b6f3d9c9"
  }
}
```

`id` is the event's id, the same on every attempt and every replay, and in the
`X-Metered-Event-Id` header: it is what a receiver deduplicates on. The body is
fixed when the delivery is created, so every attempt sends the same bytes.

| Event | When | `data` carries |
|---|---|---|
| `subscription.created` | A subscription starts | the subscription, its customer, plan version, phases and anchor |
| `subscription.canceled` | A subscription is canceled, immediately or at period end | the same, with `status` and `ends_at` — for a cancellation at period end, still ahead |
| `invoice.finalized` | An invoice gets its number and enters the ledger | the invoice's number, customer, period and total |
| `invoice.paid` | A payment succeeds | the same, and the payment reference |
| `invoice.voided` | An invoice is voided and a credit note issued | the same, and the credit note's number and reason |

A usage threshold event was planned and is not in v1 (assumptions, 32).

## Signature

```
X-Metered-Signature: t=1755939600,v1=5a4b3c...
```

`v1` is `hmac_sha256(secret, "<t>.<raw_body>")`, hex encoded. Sign and verify the
**raw body**: re-encoding JSON changes key order and whitespace, and the
signature with it.

During secret rotation an endpoint holds up to two active secrets and the header
carries a signature per secret:

```
X-Metered-Signature: t=1755939600,v1=5a4b3c...,v1=9f8e7d...
```

A receiver accepts the delivery if **any** `v1` matches. That is what makes
rotation possible without a coordinated deploy: add the new secret, switch when
ready, remove the old one.

### Verifying

```php
function verify(string $body, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
{
    $parts = [];
    foreach (explode(',', $header) as $piece) {
        [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
        $parts[$k][] = $v;
    }

    $timestamp = (int) ($parts['t'][0] ?? 0);
    if (abs(($now ?? time()) - $timestamp) > $tolerance) {
        return false; // too old, or the clock is wrong: reject either way
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    foreach ($parts['v1'] ?? [] as $candidate) {
        if (hash_equals($expected, $candidate)) {
            return true;
        }
    }

    return false;
}
```

This is `docker/webhook-receiver/verify.php`, the function the demo's receiver
runs; the suite checks it against the vectors below. Leave `$now` out: it is
there so the vectors, which are from a fixed moment, can be checked.

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

export function verify(body, header, secret, tolerance = 300) {
  const parts = header.split(',').map((p) => p.trim().split('='));
  const t = Number(parts.find(([k]) => k === 't')?.[1] ?? 0);
  if (Math.abs(Date.now() / 1000 - t) > tolerance) return false;

  const expected = createHmac('sha256', secret).update(`${t}.${body}`).digest();

  return parts
    .filter(([k]) => k === 'v1')
    .some(([, v]) => {
      const given = Buffer.from(v, 'hex');
      return given.length === expected.length && timingSafeEqual(given, expected);
    });
}
```

Two things that look optional and are not: compare in constant time, and check
the timestamp. Without the timestamp check a captured delivery can be replayed
forever.

### Test vectors

A verifier in any language can be checked against these. The signer's own
tests assert the same values (`tests/Unit/Webhooks/Domain/SignatureTest.php`),
so the documentation and the code cannot drift apart.

| | |
|---|---|
| secret | `whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw` |
| previous secret, during a rotation | `whsec_ZB4oqhnYVwJv0g7xjrCnHgT2cQ6N7KeL` |
| `t` | `1790337600` (2026-09-25T12:00:00Z) |
| raw body | `{"id":"01a0d974-2c1e-7f0a-9d3b-6a2e8c4f1b77","type":"invoice.paid","created_at":"2026-09-25T12:00:00+00:00","data":{"number":"INV-000042"}}` |
| signed string | `1790337600.` followed by the raw body |
| header, one secret | `t=1790337600,v1=b8bca8b24ab0180f1a9bf6ef35319860ab14523cf6ea8c662728ddc0fe87779c` |
| header, both secrets | `t=1790337600,v1=b8bca8b24ab0180f1a9bf6ef35319860ab14523cf6ea8c662728ddc0fe87779c,v1=aea34003af96e8cc915f95d6ff893a0c876b873146fe4c942bc16bcde5675d83` |
| empty body, first secret | `v1=37e300d82f207cbd32a2212ca7f2a0056ed6b0209810ee386153246393b4c39f` |

To check a verifier with them, set its tolerance aside (or its clock to `t`):
the vectors are from a fixed past moment, and a correct verifier rejects them
as too old otherwise.

## Secrets

A secret is `whsec_` and 43 URL-safe characters, drawn from 32 random bytes when
an endpoint is registered. It is shown once — in the answer to the request that
registered the endpoint, or in the panel's notification — and masked everywhere
after. It is stored encrypted with the application key, because unlike an API
key it has to be recovered to sign with.

Rotating gives the endpoint a new secret and keeps the old one signing alongside
it for a day (`WEBHOOKS_ROTATION_GRACE_SECONDS`), which is the window to switch
the receiver over.

## Retries

Ten attempts and nine waits between them: 1m, 5m, 30m, 1h, 2h, 4h, 8h, 12h,
24h — about two and a half days in all. Each wait moves by up to a fifth either
way, so endpoints that failed during one incident do not all retry in the same
second.

A delivery is a success on any `2xx`. `408`, `429`, any `5xx`, a timeout and a
refused connection are retried. Any other status — a `3xx` included, since
redirects are never followed — is permanent: a receiver that says "I will never
accept this" is believed, and the delivery is `failed` at once. After the tenth
failed attempt it is `dead`. Either can be replayed from the API
(`POST /webhook-deliveries/{id}/replay`), the admin panel, or
`php artisan webhooks:replay <id>`; a replay starts again from the first attempt,
with the same body.

Every attempt, successful or not, is logged with its status code, its duration,
its error if there was no answer, and the first kilobyte of what the receiver
said. The delivery's page in the panel shows them.

## Circuit breaker

Five failures in a row against one endpoint open its breaker: deliveries stop
and wait instead of hammering a service that is already down, and waiting does
not spend their attempts. After five minutes the breaker is half-open and lets
exactly one delivery through as a probe; success closes it, failure opens it
again. A probe that never reports back — a worker killed mid-request — is
replaced after another five minutes, so a breaker cannot stay half-open for
good.

What counts as a failure is what says the receiver is down: a timeout, a
refused connection, a `5xx`, `408` or `429`. A receiver that answered `400` is
up, even though it refused that delivery.

The threshold and the cooldown are `WEBHOOKS_BREAKER_THRESHOLD` and
`WEBHOOKS_BREAKER_COOLDOWN_SECONDS`. The state is on the endpoint in the panel
and in the API's `breaker` field. Pointing an endpoint at a new URL resets it.

## How a delivery travels

An event is written to the outbox in the same transaction as the change it
announces. The relay publishes it; the fan-out, behind the inbox so a
redelivered event does nothing, writes one delivery per endpoint listening to
it. `webhooks:dispatch` runs every ten seconds and queues an attempt for each
delivery that is due, new or retried, on the `webhooks` queue.

Each request also carries a W3C `traceparent` header with the trace of the
change that caused the event, so a receiver that traces its own backend can
join it. The header is not signed; verification uses only the headers in
[Signature](#signature).

An attempt leases its delivery — moves its next attempt a minute ahead — before
the request goes out, and the request is made outside any database transaction.
A worker that dies mid-request leaves the lease to run out, and the delivery is
tried again: the receiver may see an event twice, which is what at-least-once
means and why the event id is there.

## SSRF guard

A webhook URL is supplied by a tenant, which makes it a request from inside the
network to an address an attacker chooses. The guard therefore:

- requires HTTPS (HTTP is allowed only in `local`), and refuses URLs carrying
  credentials;
- resolves the hostname once and refuses the delivery if **any** address is
  private, loopback, link-local (where cloud metadata answers), carrier-grade
  NAT, multicast, documentation, benchmarking or reserved — IPv4-mapped and
  NAT64 IPv6 addresses are judged by the IPv4 address inside them;
- accepts IPv6 only from global unicast (`2000::/3`), and inside it refuses
  the blocks that are not an ordinary host: `2001::/23` (IETF assignments,
  Teredo among them), 6to4 `2002::/16` and the documentation blocks
  `2001:db8::/32` and `3fff::/20`. Anything outside `2000::/3`, including
  space assigned in the future, is refused without being listed;
- **connects to the address it validated** (curl's `CURLOPT_RESOLVE`), so a DNS
  server answering "public" to the check and `169.254.169.254` to the
  connection gets nowhere;
- never uses a proxy, even one named in `https_proxy` or `ALL_PROXY`: a proxy
  resolves the host itself, past the pinned address;
- refuses redirects entirely;
- keeps a kilobyte of the answer and stops reading it after a megabyte, and
  applies 5s connect and 10s total timeouts;
- sends through cURL, the one handler that can be told which address to
  connect to.

A refused address is a `failed` delivery with the reason in its attempt log,
not an exception: nothing was sent. `tests/Integration/Webhooks/GuardedTransportTest.php`
holds each of these against a scripted DNS server and a mocked network.

### The demo receiver

A local stack has one exception, and only one. `WEBHOOKS_TRUSTED_DESTINATION`
names a single `host:port` — in the shipped `.env.example`,
`webhook-receiver:8080`, the receiver in `compose.yaml` — that the guard lets
through although it is on the private network. Everything else still applies
to it: it is resolved once, the connection is pinned, redirects are refused.
The same host on another port, the database next to it, or any other private
address is refused as before, and the tests hold each of these.

The setting is honoured in the `local` and `demo` environments only. Set
anywhere else, the application refuses to boot. The panel marks the endpoint
that uses it.

The receiver is a stand-in for a tenant's system. Its path decides how it
answers — `/ok` (204), `/flaky` (503 twice, then 204), `/down` (always 503),
`/slow` (past the sender's timeout), `/gone` (410) — and
`http://localhost:8089` lists what arrived and whether each signature checks
out against the secrets pasted into it.

No other code path may call a tenant-supplied URL. This is the one rule in the
codebase where a shortcut turns a billing system into a proxy for the internal
network.
