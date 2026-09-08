// Scale-out test penyusutan Fixed Asset.
//
// Setup membentuk tiga periode deterministik per tenant:
// 1.000 / 3 dengan round-off 100 menghasilkan 300, 300, 400 dan NBV 0.
// Beban penuh mengulang proposal/finalisasi yang sama secara paralel untuk
// menguji idempotency dan lock; SQL oracle memeriksa saldo langsung di PostgreSQL.

import http from 'k6/http';
import { check, fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://lb';
const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const DURATION = __ENV.DURATION || '90s';
const RUN_ID = __ENV.RUN_ID || 'depreciation-r0';
const FIXTURE = __ENV.FIXTURE || './tenants.json';
const fixture = JSON.parse(open(FIXTURE));
const TENANTS = fixture.tenants;

const proposalLatency = new Trend('depreciation_proposal', true);
const finalizeLatency = new Trend('depreciation_finalize', true);
const readLatency = new Trend('depreciation_read', true);
const violations = new Counter('depreciation_violations');
const serverErrors = new Counter('depreciation_server_errors');
const timeouts = new Counter('depreciation_timeouts');

export const options = {
    scenarios: {
        saturation: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: VUS },
                { duration: DURATION, target: VUS },
                { duration: '15s', target: 0 },
            ],
            gracefulStop: '30s',
        },
    },
    thresholds: {
        depreciation_violations: ['count==0'],
        depreciation_server_errors: ['count==0'],
        http_req_failed: ['rate==0'],
        checks: ['rate==1'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    setupTimeout: '10m',
};

function auth(tenant, extra = {}) {
    return { headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${tenant.token}`, ...extra } };
}

function request(method, url, body, tenant, tags = {}, extra = {}) {
    return http.request(method, url, body === undefined ? null : JSON.stringify(body), {
        ...auth(tenant),
        tags,
        ...extra,
    });
}

function setupStage(label, requests, expected = (status) => status === 200 || status === 201) {
    const responses = http.batch(requests);
    responses.forEach((response, index) => {
        if (!expected(response.status)) {
            fail(`setup ${label} gagal untuk tenant ${index}: ${response.status} ${response.body}`);
        }
    });

    return responses;
}

function ids(responses) {
    return responses.map((response) => response.json('data.id'));
}

function periodDates(index) {
    return [
        ['2026-01-01', '2026-01-31'],
        ['2026-02-01', '2026-02-28'],
        ['2026-03-01', '2026-03-31'],
    ][index];
}

export function setup() {
    if (TENANTS.length < 100) fail(`fixture hanya memiliki ${TENANTS.length} tenant; minimal 100 diperlukan`);

    const groups = ids(setupStage('group-aset', TENANTS.map((tenant, index) => [
        'POST', `${BASE}/api/v1/group-aset`, JSON.stringify({ nama: `Depreciation group ${index}`, keterangan: 'scale test depreciation' }),
        auth(tenant, { 'Idempotency-Key': `${RUN_ID}-group-${index}` }),
    ])));
    const types = ids(setupStage('jenis-aset', TENANTS.map((tenant, index) => [
        'POST', `${BASE}/api/v1/jenis-aset`, JSON.stringify({ nama: `Depreciation type ${index}` }),
        auth(tenant, { 'Idempotency-Key': `${RUN_ID}-type-${index}` }),
    ])));
    const profiles = ids(setupStage('profil-penyusutan', TENANTS.map((tenant, index) => [
        'POST', `${BASE}/api/v1/profil-penyusutan`, JSON.stringify({
            nama: `Depreciation SLLR ${index}`,
            method: 'straight_line_life_remaining',
            frequency: 'monthly',
            year_basis: 'calendar',
            useful_life_periods: 3,
            convention: 'full_month',
        }),
        auth(tenant, { 'Idempotency-Key': `${RUN_ID}-profile-${index}` }),
    ])));
    const books = ids(setupStage('buku-penyusutan', TENANTS.map((tenant, index) => [
        'POST', `${BASE}/api/v1/buku-penyusutan`, JSON.stringify({
            nama: `Depreciation Book ${index}`,
            posting_layer: 'current',
            export_to_backoffice: false,
            depreciation_profile_id: profiles[index],
            round_off_depreciation: 10,
        }),
        auth(tenant, { 'Idempotency-Key': `${RUN_ID}-book-${index}` }),
    ])));

    setupStage('group-book-matrix', TENANTS.map((tenant, index) => [
        'PUT', `${BASE}/api/v1/group-aset/${groups[index]}/buku-penyusutan`, JSON.stringify({ rows: [{
            buku_id: books[index],
            depreciation_profile_id: profiles[index],
            useful_life_periods: 3,
            convention: 'full_month',
            depreciate: true,
            round_off_depreciation: 100,
        }] }),
        auth(tenant),
    ]), (status) => status === 200);

    setupStage('aset', TENANTS.map((tenant, index) => [
        'POST', `${BASE}/api/v1/aset`, JSON.stringify({
            legal_entity_id: tenant.legalEntityId,
            usage_org_unit_id: tenant.orgUnitId,
            group_aset_id: groups[index],
            jenis_aset_id: types[index],
            acquired_on: '2026-01-01',
            placed_in_service_on: '2026-01-01',
            acquisition_value: 1000,
            residual_value: 0,
            currency_code: 'IDR',
        }),
        auth(tenant, { 'Idempotency-Key': `${RUN_ID}-asset-${index}` }),
    ]));

    const bookResponses = setupStage('asset-books', TENANTS.map((tenant) => [
        'GET', `${BASE}/api/v1/penyusutan/buku`, null, auth(tenant),
    ]), (status) => status === 200);
    const assetBooks = bookResponses.map((response, index) => {
        const row = response.json('data.0');
        if (!row?.id) fail(`Asset Book tenant ${index} tidak terbentuk`);
        return row;
    });

    const periods = [[], [], []];
    const expectedAmounts = [300, 300, 400];
    for (let periodIndex = 0; periodIndex < 3; periodIndex += 1) {
        const [starts, ends] = periodDates(periodIndex);
        const proposalResponses = setupStage(`proposal-${periodIndex + 1}`, TENANTS.map((tenant, index) => [
            'POST', `${BASE}/api/v1/penyusutan/proposal`, JSON.stringify({
                asset_book_id: assetBooks[index].id,
                period_starts_on: starts,
                period_ends_on: ends,
            }), auth(tenant),
        ]));
        proposalResponses.forEach((response, index) => {
            const amount = Number(response.json('data.amount'));
            if (amount !== expectedAmounts[periodIndex]) {
                fail(`rounding tenant ${index}, periode ${periodIndex + 1}: ${amount} bukan ${expectedAmounts[periodIndex]}`);
            }
        });
        periods[periodIndex] = ids(proposalResponses);

        const finalResponses = setupStage(`finalize-${periodIndex + 1}`, TENANTS.map((tenant, index) => [
            'POST', `${BASE}/api/v1/penyusutan/${periods[periodIndex][index]}/finalisasi`, null, auth(tenant),
        ]), (status) => status === 200);
        finalResponses.forEach((response, index) => {
            if (response.json('data.period.status') !== 'final') fail(`finalisasi tenant ${index} periode ${periodIndex + 1} belum final`);
        });
    }

    const endingBooks = setupStage('saldo-akhir', TENANTS.map((tenant) => [
        'GET', `${BASE}/api/v1/penyusutan/buku`, null, auth(tenant),
    ]), (status) => status === 200);
    endingBooks.forEach((response, index) => {
        const row = response.json('data.0');
        if (Number(row.net_book_value) !== 0 || Number(row.round_off_depreciation) !== 100) {
            fail(`saldo akhir tenant ${index} bukan NBV 0 dengan matrix round-off 100`);
        }
    });

    return TENANTS.map((tenant, index) => ({
        ...tenant,
        assetBookId: assetBooks[index].id,
        periodIds: periods.map((period) => period[index]),
    }));
}

function violation(message) {
    violations.add(1);
    console.error(`depreciation correctness violation: ${message}`);
}

function record(response, trend, expected, label) {
    trend.add(response.timings.duration);
    if (response.status >= 500) serverErrors.add(1, { label });
    if (response.status === 0) timeouts.add(1, { label });
    const matcher = typeof expected === 'function' ? expected : (status) => status === expected;
    return check(response, { [label]: (value) => matcher(value.status) });
}

function propose(tenant, periodIndex) {
    const [starts, ends] = periodDates(periodIndex);
    const response = request('POST', `${BASE}/api/v1/penyusutan/proposal`, {
        asset_book_id: tenant.assetBookId,
        period_starts_on: starts,
        period_ends_on: ends,
    }, tenant, { op: 'depreciation_proposal' });
    if (!record(response, proposalLatency, (status) => status === 200 || status === 201, 'proposal response')) return;
    const amount = Number(response.json('data.amount'));
    if (amount !== [300, 300, 400][periodIndex] || response.json('data.id') !== tenant.periodIds[periodIndex]) {
        violation(`proposal periode ${periodIndex + 1} berubah saat retry`);
    }
}

function finalize(tenant, periodIndex) {
    const response = request('POST', `${BASE}/api/v1/penyusutan/${tenant.periodIds[periodIndex]}/finalisasi`, null, tenant, { op: 'depreciation_finalize' });
    if (!record(response, finalizeLatency, 200, 'finalize response')) return;
    if (response.json('data.period.status') !== 'final') violation(`periode ${periodIndex + 1} tidak final`);
}

function readBook(tenant) {
    const response = request('GET', `${BASE}/api/v1/penyusutan/buku`, undefined, tenant, { op: 'depreciation_read' });
    if (!record(response, readLatency, 200, 'book response')) return;
    const row = response.json('data.0');
    if (Number(row.net_book_value) !== 0 || Number(row.accumulated_depreciation) !== 1000) violation('saldo akhir berubah dari 0/1000');
}

export default function (data) {
    const tenant = data[(exec.vu.idInTest - 1) % data.length];
    const periodIndex = exec.scenario.iterationInTest % 3;
    const roll = Math.random();
    if (roll < 0.45) propose(tenant, periodIndex);
    else if (roll < 0.9) finalize(tenant, periodIndex);
    else readBook(tenant);
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];
        return value === undefined ? null : Number(value.toFixed(2));
    };
    const summary = {
        profile: PROFILE,
        vus_configured: VUS,
        tenants: TENANTS.length,
        full_load_duration_s: DURATION,
        iterations: data.metrics.iterations?.values?.count ?? 0,
        requests: data.metrics.http_reqs?.values?.count ?? 0,
        throughput_rps: metric('http_reqs', 'rate'),
        http_req_failed_rate: metric('http_req_failed', 'rate'),
        checks_rate: metric('checks', 'rate'),
        correctness_violations: data.metrics.depreciation_violations?.values?.count ?? 0,
        server_errors: data.metrics.depreciation_server_errors?.values?.count ?? 0,
        client_timeouts: data.metrics.depreciation_timeouts?.values?.count ?? 0,
        proposal: { p95: metric('depreciation_proposal', 'p(95)'), p99: metric('depreciation_proposal', 'p(99)') },
        finalize: { p95: metric('depreciation_finalize', 'p(95)'), p99: metric('depreciation_finalize', 'p(99)') },
        read: { p95: metric('depreciation_read', 'p(95)'), p99: metric('depreciation_read', 'p(99)') },
        thresholds_failed: Object.entries(data.metrics)
            .filter(([, value]) => value.thresholds && Object.values(value.thresholds).some((threshold) => threshold.ok === false))
            .map(([name]) => name),
    };
    return {
        stdout: `\n===== RINGKASAN DEPRECIATION LOAD TEST (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-depreciation-${PROFILE}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
