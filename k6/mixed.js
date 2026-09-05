import http from 'k6/http';
import { check, group } from 'k6';

/*
 * Mixed load: ingestion as a tenant's product sends it, and the reads a
 * tenant's own dashboard makes at the same time — a customer's usage this
 * month, the latest invoices, the catalog.
 *
 * The question differs from ingest.js. That profile measures the hot path on
 * its own; this one asks whether reads that go to PostgreSQL — aggregates by
 * index, invoices by project — stay quick while the consumer is writing into
 * the same tables, and whether ingestion stays quick while they run.
 *
 * Run it against a seeded tenant (`sim:seed --profile=demo`), whose customers
 * are cus_0001 … cus_NNNN:
 *
 *   METERED_URL        base URL, default http://app:8080
 *   METERED_KEY        a key with both usage:write and admin — the key sim:seed prints
 *   CUSTOMERS          how many seeded customers to spread over, default 120
 *   RUN                tag that makes this run's event ids its own
 *
 * As with ingest.js, raise API_KEY_RATE_LIMIT_PER_MINUTE for the run, or the
 * number measured is the limiter's.
 */

const base = `${__ENV.METERED_URL || 'http://app:8080'}/api/v1`;
const key = __ENV.METERED_KEY;
const customers = Number(__ENV.CUSTOMERS || 120);
const run = __ENV.RUN || `${Date.now()}-${__VU}`;

const headers = {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${key}`,
};

export const options = {
    summaryTrendStats: ['min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    scenarios: {
        ingest: {
            executor: 'constant-arrival-rate',
            exec: 'ingest',
            rate: 30,
            timeUnit: '1s',
            duration: '3m',
            preAllocatedVUs: 15,
            maxVUs: 40,
            tags: { kind: 'ingest' },
        },
        dashboard: {
            executor: 'constant-arrival-rate',
            exec: 'dashboard',
            rate: 10,
            timeUnit: '1s',
            duration: '3m',
            preAllocatedVUs: 10,
            maxVUs: 30,
            tags: { kind: 'read' },
        },
    },
    thresholds: {
        // Ingestion keeps the promise it makes on its own.
        'http_req_duration{kind:ingest}': ['p(95)<50', 'p(99)<150'],
        // Reads go to the database; a dashboard can wait a little longer,
        // not a lot.
        'http_req_duration{kind:read}': ['p(95)<150', 'p(99)<400'],
        checks: ['rate>0.99'],
    },
};

function customer() {
    return `cus_${String(1 + Math.floor(Math.random() * customers)).padStart(4, '0')}`;
}

export function ingest() {
    const now = Date.now();
    const events = [];

    for (let i = 0; i < 20; i++) {
        events.push({
            event_id: `k6-mixed-${run}-${__VU}-${__ITER}-${i}`,
            meter_code: i % 4 === 0 ? 'messages.sent' : 'api.requests',
            customer_ref: customer(),
            quantity: String(1 + (i % 7)),
            occurred_at: new Date(now - i * 250).toISOString(),
        });
    }

    const response = http.post(`${base}/usage/events`, JSON.stringify({ events }), { headers });

    check(response, {
        'ingestion answered as promised': (r) => [202, 429, 503].includes(r.status),
    });
}

export function dashboard() {
    const month = new Date();
    month.setUTCDate(1);
    month.setUTCHours(0, 0, 0, 0);

    group('customer usage this month', () => {
        const r = http.get(`${base}/customers/${customer()}/usage?from=${month.toISOString()}`, { headers });
        check(r, { 'usage read': (x) => x.status === 200 });
    });

    group('latest invoices', () => {
        const r = http.get(`${base}/invoices?limit=20`, { headers });
        check(r, { 'invoices read': (x) => x.status === 200 });
    });

    if (__ITER % 5 === 0) {
        group('catalog', () => {
            const r = http.get(`${base}/meters`, { headers });
            check(r, { 'catalog read': (x) => x.status === 200 });
        });
    }
}
