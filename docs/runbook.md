# Runbook

Operational procedures. Written as if someone other than the author is on call,
because that is the only way to find out whether they make sense.

> Populated as each milestone lands. Procedures for components that do not exist
> yet are marked as such rather than invented.

## Health

| Check | Meaning |
|---|---|
| `GET /health/live` | The process is up. Never touches dependencies. |
| `GET /health/ready` | PostgreSQL and Redis reachable, migrations applied, stream lag under threshold. |

A liveness probe that checks dependencies restarts a healthy application when a
database blips. Keep them separate.

## Ingestion is returning 503

Backpressure is working as designed: stream length or lag crossed its threshold.

1. `docker compose exec app php artisan usage:stream:status` — length, pending, consumer lag.
2. Is the consumer running? `docker compose ps usage-consumer`.
3. If it is alive but slow, look at `usage_batch_write_duration_seconds`. A jump usually means a missing partition, so the insert hit the default partition.
4. `php artisan usage:partitions:ensure` creates missing partitions for the next N days. It is idempotent.
5. Scale consumers with `docker compose up -d --scale usage-consumer=4`. Consumer groups distribute the load without configuration.

Do not raise the backpressure threshold to make the 503s stop. That converts a
visible, retryable rejection into unbounded lag.

## The consumer died mid-batch

Nothing is lost by design, but verify it rather than believing it:

1. Restart the consumer. `XAUTOCLAIM` reclaims messages idle for more than 60s.
2. `php artisan usage:reconcile --from="-2 hours"` compares aggregates against raw events.
3. Expect zero drift. A non-zero result is a bug worth an issue, not a manual correction.

## Messages in the dead-letter stream

```bash
php artisan usage:dlq:list --limit=20
php artisan usage:dlq:inspect <message-id>
php artisan usage:dlq:replay <message-id>
```

A message is dead-lettered after N delivery attempts. The usual cause is a
payload the consumer cannot parse — fix the cause first, then replay.

## Outbox is falling behind

`outbox_unpublished_age_seconds` is the signal.

1. Is `outbox-relay` running?
2. Are the queues draining? Check Horizon.
3. The relay uses `FOR UPDATE SKIP LOCKED`, so multiple relays are safe: scale it.
4. Never delete an unpublished outbox row to clear a backlog. That drops an event whose state change already committed.

## A webhook endpoint is failing

1. Admin panel → Webhooks → the endpoint. The breaker state and recent attempts, with response codes and bodies, are there.
2. Open breaker means deliveries are queued, not lost.
3. After the receiver is fixed, either wait for the cooldown probe or force a replay: `php artisan webhooks:replay --endpoint=<id> --status=dead`.
4. Sustained `4xx` other than 408/429 means the receiver is rejecting the payload — that is an integration problem, not a delivery problem.

## Rotating a webhook secret

1. `POST /webhook-endpoints/{id}/rotate-secret` adds a second active secret; both now sign every delivery.
2. The receiver adds the new secret to its verification.
3. `POST /webhook-endpoints/{id}/rotate-secret?finalize=true` drops the old one.

Never skip step two. Both signatures are sent precisely so there is no window
where a correct receiver rejects a valid delivery.

## Revoking an API key

`php artisan key:revoke <prefix>`. Keys are cached for up to 30 seconds, so
revocation takes effect within that window. If it must be immediate, flush the
key cache: `php artisan cache:forget-api-keys`.

## Verifying the audit log

`php artisan audit:verify` walks the hash chain. A failure means a row was
altered outside the application, and the command reports the first broken link.
