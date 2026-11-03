// Profil pending-purchase-v1 sebagai pengguna: satu sesi nyata per VU, jeda Uniform(1,9) detik sesudah
// setiap aksi, start Uniform(0,10). Model closed; offered load turun bila server melambat.
import http from 'k6/http';
import exec from 'k6/execution';
import { sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const BASE = __ENV.BASE;
const RUN_ID = __ENV.RUN_ID;
const PASSWORD = __ENV.COMPARE_PASSWORD;
const WARMUP = Number(__ENV.WARMUP_SECONDS || 30);
const PLATEAU = Number(__ENV.PLATEAU_SECONDS || 90);
const DRAIN = Number(__ENV.DRAIN_SECONDS || 300);
const fixture = JSON.parse(open('/fixture/fixture.json'));
const prefix = `${BASE}/api/modules/${fixture.module_id}/v1`;

export const options = {
    scenarios: {
        users: {
            executor: 'constant-vus',
            vus: Number(__ENV.VUS),
            duration: `${WARMUP + PLATEAU}s`,
            gracefulStop: `${DRAIN}s`,
        },
    },
    // Cookie sesi bertahan antar siklus; bawaan k6 mengosongkannya setiap iterasi.
    noCookiesReset: true,
    summaryTrendStats: ['count', 'avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

const unexpected = new Counter('unexpected_responses');
const cycleTotal = new Trend('cycle_total', true);
const cycleBusy = new Trend('cycle_busy', true);

let account = null;
let accountIndex = -1;

function phase() {
    const elapsed = (Date.now() - exec.scenario.startTime) / 1000;
    if (elapsed < WARMUP) return 'warmup';
    return elapsed < WARMUP + PLATEAU ? 'plateau' : 'drain';
}

function think() {
    sleep(1 + Math.random() * 8);
}

function csrf() {
    const values = http.cookieJar().cookiesForURL(BASE)['XSRF-TOKEN'];
    return values ? decodeURIComponent(values[0]) : '';
}

function call(op, kind, method, url, body, expected, extraHeaders = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...extraHeaders };
    if (body !== null) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') headers['X-XSRF-TOKEN'] = csrf();
    const tags = { op, kind, phase: phase(), name: op };
    const response = http.request(method, url, body === null ? null : JSON.stringify(body), {
        headers,
        tags,
        responseCallback: http.expectedStatuses(expected),
    });
    if (response.status !== expected) {
        unexpected.add(1, { op, phase: tags.phase, status: String(response.status) });
        throw new Error(`${op} HTTP ${response.status}`);
    }
    return response;
}

function acknowledge(receipt, outcome, assets) {
    console.log(
        'ACK ' +
            JSON.stringify({
                at: Date.now(),
                phase: phase(),
                account: {
                    index: accountIndex,
                    user_id: account.user_id,
                    tenant_id: account.tenant_id,
                    legal_entity_id: account.legal_entity_id,
                    membership_id: account.membership_id,
                },
                receipt_id: receipt.id,
                creation_key: receipt.creation_key,
                receipt_code: receipt.kode,
                outcome,
                version: receipt.version,
                asset_ids: assets.map((asset) => asset.id),
                asset_codes: assets.map((asset) => asset.kode),
                posting_id: outcome === 'completed' ? receipt.posting.posting_id : null,
            }),
    );
}

function login() {
    sleep(Math.random() * 10);
    accountIndex = (exec.vu.idInTest - 1) % fixture.accounts.length;
    account = fixture.accounts[accountIndex];
    call('login-page', 'login', 'GET', `${BASE}/login`, null, 200);
    call('login', 'login', 'POST', `${BASE}/login`, { email: account.email, password: PASSWORD }, 200);
    call('workspace', 'login', 'PUT', `${BASE}/api/v1/workspace-context`, {
        membership_id: account.membership_id,
        legal_entity_id: account.legal_entity_id,
        org_unit_id: account.department_id,
    }, 200);
    think();
}

export default function () {
    if (account === null) login();
    const document = fixture.tenants.find((tenant) => tenant.tenant_id === account.tenant_id).receipt_body;
    const receipts = `${prefix}/penerimaan-aset`;
    const started = Date.now();
    let busy = 0;
    const step = (...args) => {
        const response = call(...args);
        busy += response.timings.duration;
        think();
        return response;
    };

    step('receipt-list', 'read', 'GET', `${receipts}?per_page=40`, null, 200);
    const key = `load-${RUN_ID}-${exec.vu.idInTest}-${exec.vu.iterationInScenario}`;
    const created = step('receipt-create', 'write', 'POST', receipts, document, 201, { 'Idempotency-Key': key }).json('data');
    acknowledge(created, 'draft', []);
    const shown = step('receipt-show-draft', 'read', 'GET', `${receipts}/${created.id}`, null, 200).json('data');
    const changed = step('receipt-replace-lines', 'write', 'PATCH', `${receipts}/${created.id}`, { ...document, version: shown.version }, 200).json('data');
    acknowledge(changed, 'draft', []);
    step('receipt-preview', 'read', 'GET', `${receipts}/${created.id}/pratinjau-posting`, null, 200);
    const completed = step('receipt-complete', 'write', 'POST', `${receipts}/${created.id}/selesaikan`, { version: changed.version }, 200).json('data');
    step('receipt-show-completed', 'read', 'GET', `${receipts}/${created.id}`, null, 200);
    const assets = step('receipt-assets', 'read', 'GET', `${receipts}/${created.id}/aset`, null, 200).json('data');
    acknowledge(completed, 'completed', assets);
    step('asset-list', 'read', 'GET', `${prefix}/aset?per_page=40`, null, 200);
    for (const asset of assets) {
        step('asset-show', 'read', 'GET', `${prefix}/aset/${asset.id}`, null, 200);
    }
    cycleTotal.add(Date.now() - started, { phase: phase() });
    cycleBusy.add(busy, { phase: phase() });
}

export function handleSummary(data) {
    return { '/out/summary.json': JSON.stringify(data, null, 1), stdout: '' };
}
