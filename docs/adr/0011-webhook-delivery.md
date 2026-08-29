# 0011. Webhook signing, retries, breaker and SSRF guard

- **Status:** Accepted
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

## Accepted in M6

Built as decided: the signature and dual signing during rotation, ten
attempts with jittered waits and a dead state with replay, a breaker per
endpoint, and a guard that resolves once, refuses anything not public and
connects to the address it checked. Test vectors for the signature are pinned
in the signer's tests and published in [`webhooks.md`](../webhooks.md).

What the building changed:

**Nine waits, not ten.** Ten attempts have nine waits between them: 1m, 5m,
30m, 1h, 2h, 4h, 8h, 12h, 24h, each moved by up to a fifth either way. The
second 24h of the original list had no attempt after it.

**Deliveries are rows, and the queue only carries them.** An integration event
becomes one delivery per listening endpoint, unique on (endpoint, event), and
`webhooks:dispatch` queues whatever is due every ten seconds — new deliveries
and retries by the same path. Retries are not delayed queue jobs: a lost job
loses nothing, because the row is still due.

**The request is made outside any transaction.** An attempt leases its delivery
in one transaction, sends, and records in a second. A database transaction held
open across somebody else's server would be a lock held at their mercy. A
worker that dies mid-request leaves a lease that runs out; the receiver may see
the event twice, which at-least-once delivery allows and the event id is for.

**What counts against the breaker.** Timeouts, refused connections, `5xx`, `408`
and `429` — what says the receiver is down. A `4xx` means it is up and resets
the count. Five in a row open it for five minutes; a probe that never reports is
replaced one cooldown later. Waiting on an open breaker spends no attempt.

**A permanent refusal is `failed`, not `dead`.** Any `4xx` other than 408 and
429, any `3xx` — redirects are never followed — and an address the guard
refused end the delivery at once. Both can be replayed.

**cURL, chosen explicitly.** Pinning the address is `CURLOPT_RESOLVE`, which
only the cURL handler honours. Guzzle picked PHP's stream handler for a
streamed request and refused the option, and a mocked handler in the tests
hid it; the first delivery on the running stack found it. The transport builds
its own client over cURL, reads the answer through a progress callback that
stops after a megabyte, and a test sends through the real handler.

**Secrets are encrypted, and a rotation ends by itself.** A signing secret must
be recovered to sign with, so it is encrypted with the application key rather
than hashed like an API key, and shown once. The old secret keeps signing for a
day after a rotation and then stops; no second call finishes the rotation.

**One event was cut.** `usage.threshold_reached` needs thresholds configured
per customer and meter, and the roadmap names it the second cut; five events
are delivered ([`assumptions.md`](../assumptions.md), 32).

**One trusted destination, for the demo.** The demo needs a receiver on the
compose network, and the guard refuses every private address. Rather than a
bypass, a mode, or a proxy that would have to make the same decision,
`WEBHOOKS_TRUSTED_DESTINATION` names exactly one `host:port` that skips the
public-address check and nothing else — resolved once, pinned, no redirects.
It is honoured in `local` and `demo` only; set in any other environment, the
application does not boot. A tunnel to a public URL was rejected because
`make demo` has to work offline, and ranges of private addresses because
`172.16.0.0/12` is the whole compose network, database included.

