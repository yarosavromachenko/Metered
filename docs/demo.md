# Running the demo

The point of this document: someone who has never seen the repository should get
from `git clone` to a populated admin panel without reading anything else.

```bash
git clone https://github.com/yarosavromachenko/Metered.git metered
cd metered
make demo
```

There is no hosted instance. Everything — API, workers, database, Redis,
tracing, dashboards — runs from this repository via Docker Compose, so the demo
cannot rot, cost money, or be abused.

## What `make demo` does

1. Builds and starts the `demo` compose profile.
2. Runs migrations and creates the usage partitions.
3. Seeds the `demo` dataset (below).
4. Starts a traffic generator so the graphs are alive rather than flat.
5. Prints the URLs.

| | |
|---|---|
| Admin panel | <http://localhost:8080/admin> |
| API | <http://localhost:8080/api/v1> |
| Horizon | <http://localhost:8080/horizon> |
| Mailpit | <http://localhost:8025> |
| Grafana | <http://localhost:3000> |

If another project on your machine already holds one of those ports, set
`APP_PORT`, `MAILPIT_WEB_PORT` and friends in `.env`; nothing inside the network
cares which host port it is reached on.

At the panel, sign up. You get your own organization, project, API key and a copy
of the demo data, isolated from any other account on that machine.

## Seed profiles

| Profile | Contents | Purpose |
|---|---|---|
| `small` | 2 organizations, 10 customers, a few thousand events | Development and the test suite |
| `demo` | 3 organizations, 5 projects across two currencies, 120 customers, 4 plans covering all four pricing models, ~2M usage events across 90 days, closed invoices for past periods | What `make demo` loads |
| `heavy` | ~20M events | Benchmarking and index work only |

The `demo` profile deliberately includes awkward data, because a demo of only
happy paths demonstrates nothing: subscriptions anchored on the 29th, 30th and
31st; duplicate events; events arriving late; a customer with zero usage; an
invoice that was voided and re-issued; a webhook endpoint whose circuit breaker
is open; and two projects in different currencies under one organization, so the
dashboards have to group rather than sum.

## Why seeding does not go through the API

`sim:traffic` and `sim:seed` drive the public HTTP API on purpose — that is the
only way the ingestion path is exercised end to end. Two million historical
events over HTTP would take far longer than anyone will wait, so historical bulk
is loaded by `sim:backfill` with `COPY`, writing events and aggregates in the
same shape the consumer would have produced. `usage:reconcile` runs afterwards
and proves the two agree. The reasoning is in
[ADR-0016](adr/0016-demo-mode-and-seed-profiles.md).

## Other commands

```bash
make demo-reset   # wipe demo tenants and reseed
make load         # k6 load profile against the local stack
make down         # stop, keep data
make destroy      # stop and delete volumes
```

```bash
docker compose exec app php artisan sim:traffic --rps=200 --duration=120 --dup-rate=0.02 --late-rate=0.01
docker compose exec app php artisan sim:chaos kill-consumer
docker compose exec app php artisan usage:reconcile --from=-1h
```

`sim:chaos` kills a component mid-flight and then checks the invariants. Each
scenario ends by running `usage:reconcile`, which must report zero drift — no
lost events and no double counting. Those runs are the evidence behind the
reliability claims in the README.

## What is running

| Container | Role |
|---|---|
| `app` | Octane on FrankenPHP: the API, the admin panel and Horizon's dashboard |
| `outbox-relay` | Publishes committed integration events to the queue |
| `horizon` | Queue workers: `billing`, `webhooks`, `default`, each supervised separately |
| `usage-consumer` | Redis Stream → PostgreSQL, with aggregates in the same transaction *(M3)* |
| `scheduler` | Period close, partition creation, expiry sweeps *(M3)* |
| `postgres` | PostgreSQL 18 |
| `pgbouncer` | Transaction pooling for the web tier only; the daemons connect directly |
| `redis` | Cache, sessions, queues and the ingestion stream |
| `mailpit` | Catches every outgoing message and shows it in a browser |
| `webhook-sink` | A local endpoint to deliver webhooks to, including on purpose-broken ones *(M6)* |
| `otel-collector`, `tempo`, `prometheus`, `grafana` | Traces, metrics and dashboards *(M8)* |
| `k6` | Load scenarios, under the `load` profile *(M7)* |

## Requirements

Docker and Docker Compose. Nothing else — no local PHP, PostgreSQL or Redis.
The `demo` profile needs roughly 4 GB of RAM; the full profile with the
observability stack is more comfortable with 6 GB.

The containers that mount the source tree run as your own user, so a file one of
them creates — a published config, a generated migration — belongs to you rather
than to root. If your ids are not 1000, set `DOCKER_UID` and `DOCKER_GID`.
