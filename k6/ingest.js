import http from 'k6/http';
import { check } from 'k6';
import { Counter, Trend } from 'k6/metrics';

/*
 * The ingestion baseline: what one client sees when they instrument their own
 * product with this API.
 *
 * The shape of the test follows the shape of the promise. Ingestion claims to
 * be a hot-path endpoint whose latency does not depend on the database, so
 * what is measured is the latency of `POST /usage/events` under a load that
 * keeps the consumer busy — not the throughput of the whole pipeline, which is
 * bounded by PostgreSQL and is a different question with a different answer.
 *
 * Environment:
 *   METERED_URL       base URL, default http://app:8080
 *   METERED_KEY       an API key with the usage:write scope
 *   METERED_METER     the meter code events are sent under
 *   METERED_CUSTOMER  the customer reference they are sent for
 *   BATCH             events per request, default 50
 *   RUN               tag that makes this run's event ids its own
 *
 * The per-key rate limit applies here like anywhere else, so a run against
 * the default 600 requests a minute measures the limiter. Raise it for the
 * run: API_KEY_RATE_LIMIT_PER_MINUTE=1000000 docker compose up -d app.
 */

const url = `${__ENV.METERED_URL || 'http://app:8080'}/api/v1/usage/events`;
const key = __ENV.METERED_KEY;
const meter = __ENV.METERED_METER || 'api.requests';
const customer = __ENV.METERED_CUSTOMER || 'cus_bench';
const batch = Number(__ENV.BATCH || 50);

// Event ids have to be unique between runs and stable within one.
//
// Unique between runs, because deduplication is doing its job: a second run
// sending the first run's ids is answered 202 by the endpoint and then
// discarded by the consumer, so the writes the run is supposed to measure
// never happen and the lag it reports is the lag of doing nothing.
//
// Stable within a run, because the init context executes once per VU: the
// tag a VU computes here is the same for all of its iterations, so a request
// k6 retries carries the ids it carried the first time and is deduplicated,
// which is the behaviour this profile is meant to exercise.
//
// Set RUN explicitly to name a run — it is the prefix its events and any
// rejections carry in the database afterwards.
const run = __ENV.RUN || `${Date.now()}-${__VU}`;

const accepted = new Counter('events_accepted');
const shed = new Counter('requests_shed');
const throttled = new Counter('requests_throttled');
const batchSize = new Trend('batch_size');

export const options = {
    // p99 as well as p95: the tail is what a client notices, and an average
    // hides it completely.
    summaryTrendStats: ['min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    scenarios: {
        // A warm-up that is not measured, then a plateau that is: a p95 that
        // includes the first request against a cold worker measures the boot,
        // not the endpoint.
        warmup: {
            executor: 'constant-vus',
            vus: 2,
            duration: '15s',
            tags: { phase: 'warmup' },
        },
        plateau: {
            executor: 'constant-arrival-rate',
            rate: 40,
            timeUnit: '1s',
            duration: '60s',
            preAllocatedVUs: 20,
            maxVUs: 60,
            startTime: '20s',
            tags: { phase: 'plateau' },
        },
    },
    thresholds: {
        // The promise this endpoint makes, as a number that fails the run.
        'http_req_duration{phase:plateau}': ['p(95)<50', 'p(99)<150'],
        // Every answer has to be one the API promises. The per-key rate
        // limit is a policy with its own tests; a run that trips it is
        // measuring the limiter, so raise the limit for the run rather than
        // reading the result as ingestion being slow.
        checks: ['rate>0.99'],
    },
};

function events(count) {
    const now = Date.now();
    const list = [];

    for (let i = 0; i < count; i++) {
        list.push({
            // Unique per event within the run, and identical if k6 retries
            // this request: the id is what makes a resend safe, and a profile
            // that sent a new one every time would never exercise
            // deduplication at all.
            event_id: `k6-${run}-${__VU}-${__ITER}-${i}`,
            meter_code: meter,
            customer_ref: customer,
            quantity: '1',
            occurred_at: new Date(now - i * 1000).toISOString(),
            properties: { source: 'k6', region: 'eu-central' },
        });
    }

    return list;
}

export default function () {
    const body = JSON.stringify({ events: events(batch) });

    const response = http.post(url, body, {
        headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${key}`,
        },
    });

    check(response, {
        'answered as the API promises': (r) => [202, 429, 503].includes(r.status),
        'never a server error': (r) => r.status < 500 || r.status === 503,
    });

    if (response.status === 202) {
        accepted.add(batch);
        batchSize.add(batch);
    }

    // A 503 with Retry-After is a correct answer under load, not a failure:
    // the run reports how often it happened rather than hiding it. So is a
    // 429 — it means the key's own budget ran out, which says nothing about
    // how fast ingestion is.
    if (response.status === 503) {
        shed.add(1);
    }

    if (response.status === 429) {
        throttled.add(1);
    }
}
