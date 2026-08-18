// Scale-out test Work Order maintenance.
//
// Setup membuat satu fixture Work Order per tenant dari aset yang sudah disiapkan
// oleh master-data.js. Beban penuh kemudian menguji jalur create, show, transisi
// draft -> dijadwalkan -> dikerjakan -> selesai, hasil pelaksanaan, idempotency,
// isolasi tenant, dan batas permission di empat instance API.

import http from 'k6/http';
import { check, fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://lb';
const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const DURATION = __ENV.DURATION || '90s';
const RUN_ID = __ENV.RUN_ID || 'wo-r0';

const fixture = JSON.parse(open('./tenants.json'));
const TENANTS = fixture.tenants;
const NARROW = fixture.narrow;

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
const timeouts = new Counter('client_timeouts');
const crossTenantProbes = new Counter('cross_tenant_probes');
const scopeProbes = new Counter('permission_scope_probes');
const idempotencyReplays = new Counter('idempotency_replays');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

const correctnessThresholds = {
    correctness_violations: ['count==0'],
    server_errors: ['count==0'],
    http_req_failed: ['rate<0.001'],
    checks: ['rate>0.999'],
};

export const options =
    PROFILE === 'latency16'
        ? {
              scenarios: { latency: { executor: 'constant-vus', vus: 16, duration: '90s', gracefulStop: '20s' } },
              thresholds: { ...correctnessThresholds, ...LATENCY_SLO },
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
          }
        : {
              scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '45s' } },
              thresholds: correctnessThresholds,
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              setupTimeout: '5m',
          };

function auth(token, extra = {}) {
    return { headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...extra } };
}

function record(response, latency, label) {
    latency.add(response.timings.duration);
    if (response.status === 0) {
        timeouts.add(1, { label });
    } else if (response.status === 502 || response.status === 504) {
        gatewayErrors.add(1, { label });
    } else if (response.status >= 500) {
        serverErrors.add(1, { label });
    }
    return response;
}

function createMaster(tenant, resource, name, key, extra = {}) {
    return record(
        http.post(`${BASE}/api/v1/${resource}`, JSON.stringify({ nama: name, ...extra }), {
            ...auth(tenant.token, { 'Idempotency-Key': key }),
            tags: { op: 'create', resource },
        }),
        writeLatency,
        `create ${resource}`,
    );
}

function workOrderBody(tenant) {
    return {
        legal_entity_id: tenant.legalEntityId,
        responsible_org_unit_id: tenant.orgUnitId,
        tipe_work_order_id: tenant.workOrderTypeId,
        keterangan: 'scale-out work order',
        diharapkan_mulai: '2026-01-01 08:00:00',
        diharapkan_selesai: '2026-01-01 10:00:00',
        dijadwalkan_mulai: '2026-01-01 08:00:00',
        dijadwalkan_selesai: '2026-01-01 10:00:00',
        details: [{
            asset_id: tenant.assetId,
            maintenance_job_type_id: tenant.jobTypeId,
            estimasi_jam: 1,
            catatan: 'scale-out work order',
        }],
    };
}

function createWorkOrder(tenant, key) {
    return record(
        http.post(`${BASE}/api/v1/pemeliharaan-aset`, JSON.stringify(workOrderBody(tenant)), {
            ...auth(tenant.token, { 'Idempotency-Key': key }),
            tags: { op: 'create', resource: 'pemeliharaan-aset' },
        }),
        writeLatency,
        'create work order',
    );
}

function transition(tenant, id, version, status) {
    return record(
        http.post(`${BASE}/api/v1/pemeliharaan-aset/${id}/status`, JSON.stringify({ ke_status: status, version }), {
            ...auth(tenant.token),
            tags: { name: 'work-order transition', op: 'transition', resource: 'pemeliharaan-aset', status },
        }),
        writeLatency,
        `transition ${status}`,
    );
}

export function setup() {
    const assetResponses = http.batch(TENANTS.map((tenant) => [
        'GET', `${BASE}/api/v1/aset`, null, auth(tenant.token),
    ]));
    const assets = assetResponses.map((response, index) => {
        if (response.status !== 200) {
            fail(`setup aset gagal untuk tenant ${index}: ${response.status} ${response.body}`);
        }
        const asset = response.json('data.0');
        if (!asset?.id || !asset?.jenis_aset_id) {
            fail(`setup aset kosong untuk tenant ${index}`);
        }
        return asset;
    });

    const workOrderTypes = TENANTS.map((tenant, index) => createMaster(
        tenant,
        'tipe-work-order',
        `WO type ${RUN_ID}-${index}`,
        `wo-type-${RUN_ID}-${index}`,
    ));
    const jobTypes = TENANTS.map((tenant, index) => createMaster(
        tenant,
        'maintenance-job-types',
        `WO job type ${RUN_ID}-${index}`,
        `wo-job-${RUN_ID}-${index}`,
        { category_code: 'corrective' },
    ));

    const prepared = TENANTS.map((tenant, index) => {
        const workOrderType = workOrderTypes[index];
        const jobType = jobTypes[index];
        if (![200, 201].includes(workOrderType.status) || ![200, 201].includes(jobType.status)) {
            fail(`setup work order master gagal untuk tenant ${index}: ${workOrderType.status}/${jobType.status}`);
        }
        return { ...tenant, assetId: assets[index].id, assetTypeId: assets[index].jenis_aset_id, workOrderTypeId: workOrderType.json('data.id'), jobTypeId: jobType.json('data.id') };
    });

    const seedResponses = prepared.map((tenant, index) => createWorkOrder(tenant, `wo-seed-${RUN_ID}-${index}`));
    const seedWorkOrderIds = seedResponses.map((response, index) => {
        if (![200, 201].includes(response.status)) {
            fail(`setup work order gagal untuk tenant ${index}: ${response.status} ${response.body}`);
        }
        return response.json('data.id');
    });

    return { tenants: prepared.map((tenant, index) => ({ ...tenant, seedWorkOrderId: seedWorkOrderIds[index] })) };
}

function lifecycle(tenant, key) {
    const created = createWorkOrder(tenant, key);
    if (![200, 201].includes(created.status)) {
        return;
    }

    const id = created.json('data.id');
    const scheduled = transition(tenant, id, 1, 'dijadwalkan');
    if (scheduled.status !== 200) return;
    const started = transition(tenant, id, 2, 'dikerjakan');
    if (started.status !== 200) return;

    const shown = record(
        http.get(`${BASE}/api/v1/pemeliharaan-aset/${id}`, { ...auth(tenant.token), tags: { name: 'work-order show', op: 'show', resource: 'pemeliharaan-aset' } }),
        readLatency,
        'show work order',
    );
    if (shown.status !== 200 || !shown.json('data.details.0.id')) {
        violation('work_order_detail_missing');
        return;
    }

    const jobId = shown.json('data.details.0.id');
    const execution = record(
        http.patch(`${BASE}/api/v1/pemeliharaan-aset/${id}/jobs/${jobId}/execution`, JSON.stringify({ aktual_jam: 1 }), {
            ...auth(tenant.token), tags: { name: 'work-order execution', op: 'execution', resource: 'pemeliharaan-aset' },
        }),
        writeLatency,
        'save execution',
    );
    check(execution, { 'hasil pelaksanaan tersimpan': (response) => response.status === 200 });
    if (execution.status === 200) {
        const finished = transition(tenant, id, 3, 'selesai');
        check(finished, { 'work order selesai': (response) => response.status === 200 });
    }
}

function idempotencyRace(tenant) {
    const key = `wo-race-${RUN_ID}-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
    const params = { ...auth(tenant.token, { 'Idempotency-Key': key }), tags: { op: 'idempotency', resource: 'pemeliharaan-aset' } };
    const body = JSON.stringify(workOrderBody(tenant));
    const [first, second] = http.batch([
        ['POST', `${BASE}/api/v1/pemeliharaan-aset`, body, params],
        ['POST', `${BASE}/api/v1/pemeliharaan-aset`, body, params],
    ]);
    [first, second].forEach((response) => record(response, writeLatency, 'work order idempotency'));
    const bothOk = check({ first, second }, { 'idempotency work order keduanya 2xx': () => [first, second].every((response) => response.status >= 200 && response.status < 300) });
    if (!bothOk) return;
    if (first.json('data.id') !== second.json('data.id')) {
        violations.add(1, { kind: 'work_order_idempotency_two_records' });
    } else if (first.status === 200 || second.status === 200) {
        idempotencyReplays.add(1);
    }
}

function probes(data, tenantIndex, tenant) {
    const victim = data.tenants[(tenantIndex + 1) % data.tenants.length];
    crossTenantProbes.add(1);
    const read = http.get(`${BASE}/api/v1/pemeliharaan-aset/${victim.seedWorkOrderId}`, {
        ...auth(tenant.token), tags: { name: 'work-order cross-tenant read', op: 'probe_read', resource: 'pemeliharaan-aset' }, responseCallback: http.expectedStatuses(404),
    });
    check(read, { 'baca work order tenant lain 404': (response) => response.status === 404 });
    if (read.status === 200) violations.add(1, { kind: 'work_order_cross_tenant_read' });

    const denied = http.get(`${BASE}/api/v1/pemeliharaan-aset`, {
        ...auth(NARROW.token), tags: { op: 'probe_scope_denied', resource: 'pemeliharaan-aset' }, responseCallback: http.expectedStatuses(403),
    });
    scopeProbes.add(1);
    check(denied, { 'permission work order tetap 403': (response) => response.status === 403 });
    if (denied.status === 200) violations.add(1, { kind: 'work_order_permission_escalation' });
}

export default function (data) {
    const tenantIndex = Math.floor(Math.random() * data.tenants.length);
    const tenant = data.tenants[tenantIndex];
    const roll = Math.random();
    if (roll < 0.45) {
        lifecycle(tenant, `wo-${RUN_ID}-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`);
    } else if (roll < 0.65) {
        const list = record(http.get(`${BASE}/api/v1/pemeliharaan-aset`, { ...auth(tenant.token), tags: { op: 'list', resource: 'pemeliharaan-aset' } }), readLatency, 'list work order');
        check(list, { 'daftar work order 200': (response) => response.status === 200 });
    } else if (roll < 0.78) {
        idempotencyRace(tenant);
    } else if (roll < 0.9) {
        probes(data, tenantIndex, tenant);
    } else {
        const show = record(http.get(`${BASE}/api/v1/pemeliharaan-aset/${tenant.seedWorkOrderId}`, { ...auth(tenant.token), tags: { name: 'work-order show', op: 'show', resource: 'pemeliharaan-aset' } }), readLatency, 'show seeded work order');
        check(show, { 'show work order 200': (response) => response.status === 200 });
    }
}

function violation(kind) {
    violations.add(1, { kind });
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];
        return value === undefined ? null : Number(value.toFixed(2));
    };
    const summary = {
        profile: PROFILE,
        vus_configured: PROFILE === 'latency16' ? 16 : VUS,
        tenants: TENANTS.length,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: data.metrics.iterations?.values?.count ?? 0,
        requests: data.metrics.http_reqs?.values?.count ?? 0,
        throughput_rps: metric('http_reqs', 'rate'),
        http_req_failed_rate: metric('http_req_failed', 'rate'),
        checks_rate: metric('checks', 'rate'),
        correctness_violations: data.metrics.correctness_violations?.values?.count ?? 0,
        server_errors: data.metrics.server_errors?.values?.count ?? 0,
        gateway_errors: data.metrics.gateway_errors?.values?.count ?? 0,
        client_timeouts: data.metrics.client_timeouts?.values?.count ?? 0,
        idempotency_replays: data.metrics.idempotency_replays?.values?.count ?? 0,
        cross_tenant_probes: data.metrics.cross_tenant_probes?.values?.count ?? 0,
        permission_scope_probes: data.metrics.permission_scope_probes?.values?.count ?? 0,
        read: { p50: metric('op_read', 'med'), p95: metric('op_read', 'p(95)'), p99: metric('op_read', 'p(99)') },
        write: { p50: metric('op_write', 'med'), p95: metric('op_write', 'p(95)'), p99: metric('op_write', 'p(99)') },
        thresholds_failed: Object.entries(data.metrics).filter(([, value]) => value.thresholds && Object.values(value.thresholds).some((threshold) => threshold.ok === false)).map(([name]) => name),
    };
    return {
        stdout: `\n===== RINGKASAN LOAD TEST (work-order ${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-work-order-${PROFILE}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
