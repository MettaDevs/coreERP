// Login sekali untuk setiap akun fixture dan catat cookie sesinya, agar run beban dapat memulai dari
// pengguna yang sudah berada di dalam sistem. Bukan pengukuran: tidak ada SLO, laju mengikuti kemampuan
// stack. Cookie hanya ditulis ke log k6 di folder private dan tidak pernah dicetak ke stdout.
import http from 'k6/http';
import exec from 'k6/execution';
import { SharedArray } from 'k6/data';

const BASE = __ENV.BASE;
const PASSWORD = __ENV.COMPARE_PASSWORD;
const fixtureFile = '/fixture/fixture.json';
const accounts = new SharedArray('accounts', () => JSON.parse(open(fixtureFile)).accounts);
const ATTEMPTS = 3;

export const options = {
    scenarios: {
        sessions: {
            executor: 'shared-iterations',
            vus: Number(__ENV.VUS || 8),
            iterations: accounts.length,
            maxDuration: '30m',
        },
    },
};

function csrf() {
    const values = http.cookieJar().cookiesForURL(BASE)['XSRF-TOKEN'];
    return values ? decodeURIComponent(values[0]) : '';
}

function request(method, url, body, expected) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (body !== null) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') headers['X-XSRF-TOKEN'] = csrf();
    const response = http.request(method, url, body === null ? null : JSON.stringify(body), {
        headers,
        responseCallback: http.expectedStatuses(expected),
    });
    if (response.status !== expected) throw new Error(`${method} ${url} HTTP ${response.status}`);
}

export default function () {
    const index = exec.scenario.iterationInTest;
    const account = accounts[index];
    for (let attempt = 1; attempt <= ATTEMPTS; attempt++) {
        http.cookieJar().clear(BASE);
        try {
            request('GET', `${BASE}/login`, null, 200);
            request('POST', `${BASE}/login`, { email: account.email, password: PASSWORD }, 200);
            request('PUT', `${BASE}/api/v1/workspace-context`, {
                membership_id: account.membership_id,
                legal_entity_id: account.legal_entity_id,
                org_unit_id: account.department_id,
            }, 200);
            const cookies = {};
            for (const [name, values] of Object.entries(http.cookieJar().cookiesForURL(BASE))) cookies[name] = values[0];
            console.log('SESSION ' + JSON.stringify({ index, user_id: account.user_id, cookies }));
            return;
        } catch (error) {
            console.warn(`login ${index} attempt ${attempt}: ${error.message}`);
        }
    }
}

export function handleSummary(data) {
    return { '/out/summary.json': JSON.stringify(data, null, 1), stdout: '' };
}
