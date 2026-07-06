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
 */

const url = `${__ENV.METERED_URL || 'http://app:8080'}/api/v1/usage/events`;
const key = __ENV.METERED_KEY;
const meter = __ENV.METERED_METER || 'api.requests';
const customer = __ENV.METERED_CUSTOMER || 'cus_bench';
const batch = Number(__ENV.BATCH || 50);

const accepted = new Counter('events_accepted');
const shed = new Counter('requests_shed');
const batchSize = new Trend('batch_size');

export const options = {
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
        'http_req_failed{phase:plateau}': ['rate<0.01'],
    },
};

function events(count) {
    const now = Date.now();
    const list = [];

    for (let i = 0; i < count; i++) {
        list.push({
            // Unique per event and stable if this request is retried by k6:
            // the id is what makes a resend safe, and a test that sends a new
            // id every time would never exercise deduplication.
            event_id: `k6-${__VU}-${__ITER}-${i}`,
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
        'accepted or shed': (r) => r.status === 202 || r.status === 503,
        'never a server error': (r) => r.status < 500 || r.status === 503,
    });

    if (response.status === 202) {
        accepted.add(batch);
        batchSize.add(batch);
    }

    // A 503 with Retry-After is a correct answer under load, not a failure:
    // the run reports how often it happened rather than hiding it.
    if (response.status === 503) {
        shed.add(1);
    }
}
