// Profil pending-purchase-v1 sebagai pengguna: satu sesi nyata per VU, jeda Uniform(1,9) detik sesudah
// setiap aksi, start siklus Uniform(0,10) sesudah jendela login. Model closed; offered load turun bila
// server melambat. Login (bcrypt dua kali) diukur di jendela sendiri agar tidak tumpang tindih plateau.
import http from 'k6/http';
import exec from 'k6/execution';
import { sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter, Trend } from 'k6/metrics';

const BASE = __ENV.BASE;
const RUN_ID = __ENV.RUN_ID;
const PASSWORD = __ENV.COMPARE_PASSWORD;
const LOGIN = Number(__ENV.LOGIN_SECONDS || 60);
const WARMUP = Number(__ENV.WARMUP_SECONDS || 30);
const PLATEAU = Number(__ENV.PLATEAU_SECONDS || 90);
const DRAIN = Number(__ENV.DRAIN_SECONDS || 300);
// Fixture dimuat sekali untuk semua VU; parse per VU menghabiskan 2 GB pada 800 VU.
const fixtureFile = '/fixture/fixture.json';
const accounts = new SharedArray('accounts', () => JSON.parse(open(fixtureFile)).accounts);
const tenants = new SharedArray('tenants', () => JSON.parse(open(fixtureFile)).tenants.map((tenant) => ({ tenant_id: tenant.tenant_id, receipt_body: tenant.receipt_body })));
const moduleId = new SharedArray('module', () => [JSON.parse(open(fixtureFile)).module_id])[0];
const prefix = `${BASE}/api/modules/${moduleId}/v1`;

export const options = {
    scenarios: {
        users: {
            executor: 'constant-vus',
            vus: Number(__ENV.VUS),
            duration: `${LOGIN + WARMUP + PLATEAU}s`,
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
let document = null;
let cycling = false;
let loginAttempted = false;

function elapsed() {
    return (Date.now() - exec.scenario.startTime) / 1000;
}

function phase() {
    const seconds = elapsed();
    if (seconds < LOGIN) return 'login';
    if (seconds < LOGIN + WARMUP) return 'warmup';
    return seconds < LOGIN + WARMUP + PLATEAU ? 'plateau' : 'drain';
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
    // Sebaran login hanya untuk percobaan pertama; login yang gagal diulang sesudah think time.
    if (!loginAttempted) sleep(Math.random() * LOGIN * 0.5);
    loginAttempted = true;
    const index = (exec.vu.idInTest - 1) % accounts.length;
    const candidate = accounts[index];
    call('login-page', 'login', 'GET', `${BASE}/login`, null, 200);
    call('login', 'login', 'POST', `${BASE}/login`, { email: candidate.email, password: PASSWORD }, 200);
    call('workspace', 'login', 'PUT', `${BASE}/api/v1/workspace-context`, {
        membership_id: candidate.membership_id,
        legal_entity_id: candidate.legal_entity_id,
        org_unit_id: candidate.department_id,
    }, 200);
    accountIndex = index;
    account = candidate;
    if (document === null) {
        for (let position = 0; position < tenants.length && document === null; position++) {
            const tenant = tenants[position];
            if (tenant.tenant_id === candidate.tenant_id) document = tenant.receipt_body;
        }
    }
}

export default function () {
    try {
        if (account === null) login();
        if (!cycling) {
            sleep(Math.max(0, LOGIN - elapsed()) + Math.random() * 10);
            cycling = true;
        }
        cycle();
    } catch (error) {
        // Respons tak terduga sudah dihitung di call(); sesi yang ditolak harus login ulang, dan
        // jeda tetap dijalankan agar VU yang gagal tidak berputar tanpa think time.
        if (/HTTP (0|401|419)$/.test(String(error.message))) account = null;
        think();
    }
}

function cycle() {
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
