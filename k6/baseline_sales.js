import http from 'k6/http';
import { check, sleep } from 'k6';

// k6 Baseline Load Testing Script for TIKETI (Pre-Ledger Baseline)
// Target: 50 concurrent sellers operating on ticketing & printing endpoints.

export const options = {
    stages: [
        { duration: '30s', target: 10 },  // Ramp up to 10 users
        { duration: '1m',  target: 50 },  // Sustained load at 50 concurrent sellers
        { duration: '30s', target: 0  },  // Ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<250', 'p(99)<500'], // SLA: 95% < 250ms, 99% < 500ms
        http_req_failed: ['rate<0.01'],               // Error rate < 1%
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

export function setup() {
    // Perform authentication if required, or fetch active trip ID
    const res = http.get(`${BASE_URL}/up`);
    check(res, { 'system healthy': (r) => r.status === 200 });

    return {
        baseUrl: BASE_URL,
    };
}

export default function (data) {
    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };

    // 1. Fetch catalog / trips
    const catalogRes = http.get(`${data.baseUrl}/api/routes`, params);
    check(catalogRes, {
        'catalog status 200': (r) => r.status === 200,
    });

    sleep(1);
}

export function teardown(data) {
    // Teardown actions / metrics summary
}
