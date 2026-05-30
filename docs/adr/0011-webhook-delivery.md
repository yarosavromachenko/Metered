# 0011. Webhook signing, retries, breaker and SSRF guard

- **Status:** Proposed
- **Date:** 2026-05-30

## Context

A webhook is an outbound HTTP request to a URL the tenant supplies, carrying data
the tenant's systems will act on. That single sentence contains three separate
problems: the receiver must be able to prove the payload came from us, the
receiver will sometimes be down, and the URL is attacker-controlled input that
our servers will connect to from inside the network.

## Decision

**Signing.** `X-Metered-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "<t>.<raw body>")>`.
The timestamp is inside the signed string, so a captured delivery cannot be
replayed once the receiver's tolerance window passes. An endpoint may hold two
active secrets during rotation, and the header then carries one `v1` per secret;
the receiver accepts if any matches.

**Retries.** Ten attempts with exponential backoff and jitter: 1m, 5m, 30m, 1h,
2h, 4h, 8h, 12h, 24h, 24h. Any `2xx` is success. A `4xx` other than 408 and 429
is permanent and stops retrying. After the last attempt the delivery is `dead`
and replayable from the API or the admin panel.

**Circuit breaker per endpoint.** N consecutive failures open it and deliveries
queue instead of being attempted. After a cooldown it is half-open and one probe
is allowed; success closes it, failure re-opens it.

**SSRF guard.** HTTPS only outside `local`. Resolve the hostname, reject private,
loopback, link-local, multicast and cloud metadata ranges, then **connect to the
resolved address that was validated** rather than re-resolving. Refuse redirects.
Cap the response body read. Timeouts: 5s connect, 10s total.

Every attempt is recorded in `webhook_deliveries` with status, response code,
duration and a truncated response body.

## Consequences

Receivers can verify authenticity with a few lines of code, in any language, and
`docs/webhooks.md` ships working examples plus test vectors.

Rotation needs no coordinated deploy: add a secret, both are sent, the receiver
switches, the old one is removed. Without dual signing there is always a window
where a correct receiver rejects a valid delivery.

Jitter matters more than it looks. Without it, every endpoint that failed during
one incident retries in the same second, and the recovery is a self-inflicted
thundering herd.

The breaker protects both sides: a struggling receiver is not hammered, and
workers are not consumed waiting on timeouts for an endpoint that is down.

Connecting to the validated address is what defeats DNS rebinding, where a
hostname resolves to a public address during the check and to `169.254.169.254`
a moment later. Refusing redirects closes the same hole by a different route. The
cost is that legitimate redirects are not followed, which is documented — a
webhook endpoint that redirects is a misconfiguration.

Storing every attempt makes the delivery log grow quickly and makes support
possible. Response bodies are truncated and never contain our secrets.

## Alternatives considered

**Signature without a timestamp.** Simpler, and replayable forever.

**Asymmetric signatures.** A receiver could verify with a public key and we would
never share a secret. Rejected as heavier than the threat model warrants; HMAC is
what the ecosystem expects and what integration code already has.

**Retry forever.** Rejected: unbounded queues and no moment at which someone is
told the integration is broken. Dead-lettering forces the conversation.

**No circuit breaker, rely on backoff.** Backoff spaces retries for one delivery;
it does nothing about a thousand deliveries queued for the same dead endpoint.

**Allow-list of receiver domains instead of an SSRF guard.** Safer in theory,
unusable in practice for a self-service product.
