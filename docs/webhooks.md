# Webhooks

Metered tells a tenant's systems what happened: a subscription was created, an
invoice was finalized, a payment succeeded. Delivery is at-least-once, so every
payload carries an `id` a receiver can deduplicate on.

## Payload

```json
{
  "id": "01J9X2H8M4QK3S0T7V2B9C1D5E",
  "type": "invoice.finalized",
  "created_at": "2026-08-23T09:00:00Z",
  "data": {
    "invoice_id": "01J9X2...",
    "number": "ACME-2026-000117",
    "customer": "acme-corp",
    "total": {"amount": 184200, "currency": "EUR"},
    "period": {"start": "2026-07-01T00:00:00Z", "end": "2026-08-01T00:00:00Z"}
  }
}
```

| Event | When |
|---|---|
| `subscription.created` | A subscription starts |
| `subscription.canceled` | A subscription ends, immediately or at period end |
| `invoice.finalized` | An invoice gets its number and enters the ledger |
| `invoice.paid` | A payment succeeds |
| `invoice.voided` | An invoice is voided and a credit note issued |
| `usage.threshold_reached` | Usage crosses a configured threshold |

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
function verify(string $body, string $header, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $header) as $piece) {
        [$k, $v] = explode('=', trim($piece), 2);
        $parts[$k][] = $v;
    }

    $timestamp = (int) ($parts['t'][0] ?? 0);
    if (abs(time() - $timestamp) > $tolerance) {
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

Test vectors live next to the signer's unit tests, so an implementation in any
language can be checked against them.

## Retries

Ten attempts, exponential backoff with jitter. Default schedule: 1m, 5m, 30m,
1h, 2h, 4h, 8h, 12h, 24h, 24h. Jitter prevents every endpoint that failed during
one incident from retrying in the same second.

A delivery is a success on any `2xx`. `4xx` other than `408` and `429` is treated
as permanent and stops the retries — a receiver that says "I will never accept
this" is believed. After the last attempt the delivery is `dead` and can be
replayed from the API or the admin panel.

## Circuit breaker

Consecutive failures against one endpoint open its breaker: deliveries stop and
queue up instead of hammering a service that is already down. After a cooldown
the breaker is half-open and lets exactly one probe through; success closes it,
failure opens it again. The state is visible in the admin panel and in the
`webhook_endpoints` row.

## SSRF guard

A webhook URL is supplied by a tenant, which makes it a request from inside the
network to an address an attacker chooses. The guard therefore:

- requires HTTPS (HTTP is allowed only in `local`);
- resolves the hostname and rejects private, loopback, link-local, multicast and
  cloud metadata ranges;
- **connects to the address it validated**, defeating a DNS rebind between the
  check and the connection;
- refuses redirects entirely;
- caps the response body it reads, and applies 5s connect and 10s total timeouts.

No other code path may call a tenant-supplied URL. This is the one rule in the
codebase where a shortcut turns a billing system into a proxy for the internal
network.
