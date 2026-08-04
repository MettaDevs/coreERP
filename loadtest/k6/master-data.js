// Load test master data Management Aset.
//
// Dua profil, karena keduanya menjawab pertanyaan berbeda:
//
//   PROFILE=saturation  1000+ VU serentak, 100+ tenant, 4 server instance.
//                       Yang digate: KEBENARAN dan ketahanan, bukan latensi.
//                       Tidak boleh ada 5xx, tidak boleh ada kebocoran lintas tenant,
//                       tidak boleh ada eskalasi hak, idempotency wajib tetap utuh.
//
//   PROFILE=latency     Naik bertahap sampai titik yang masih memenuhi SLO.
//                       Yang digate: p95/p99 per jenis operasi.
//                       Angka latensi pada beban jenuh adalah antrean, bukan biaya kode.
//
// Keduanya wajib lewat sebelum sebuah modul dianggap selesai.

import http from 'k6/http';
import { check, fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://lb';
const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const DURATION = __ENV.DURATION || '90s';
// Kunci seed dibuat unik per run: record seed ikut diubah oleh workload, dan
// replay membandingkan payload dengan keadaan record saat ini.
const RUN_ID = __ENV.RUN_ID || 'r0';

const fixture = JSON.parse(open('./tenants.json'));
const TENANTS = fixture.tenants;
const NARROW = fixture.narrow;

const CHAINED = {
    'kategori-aset': { parentField: 'group_aset_id', seed: 'groupAsetId' },
    'jenis-aset': { parentField: 'kategori_aset_id', seed: 'kategoriAsetId' },
    'entitas-aset': { parentField: 'jenis_aset_id', seed: 'jenisAsetId' },
};
const STANDALONE = ['group-aset', 'kondisi-aset', 'pabrikan-aset', 'item-checklist-maintenance', 'analisa-maintenance'];
const KODE_PREFIX = {
    'entitas-aset': 'EA-',
    'group-aset': 'GA-',
    'kategori-aset': 'KA-',
    'jenis-aset': 'JA-',
    'kondisi-aset': 'KD-',
    'pabrikan-aset': 'PB-',
    'item-checklist-maintenance': 'IC-',
    'analisa-maintenance': 'AM-',
};

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const idempotencyReplays = new Counter('idempotency_replays');
const crossTenantProbes = new Counter('cross_tenant_probes');
const scopeProbes = new Counter('permission_scope_probes');
// Status 0 berarti klien menyerah sebelum server menjawab: itu batas kapasitas,
// bukan jawaban salah. Dihitung terpisah agar tidak tertukar dengan kesalahan kebenaran.
const timeouts = new Counter('client_timeouts');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

// Gate kebenaran berlaku pada kedua profil. Gate latensi hanya pada profil latency,
// karena pada beban jenuh yang terukur adalah kedalaman antrean, bukan efisiensi kode.
const correctnessThresholds = {
    correctness_violations: ['count==0'],
    server_errors: ['count==0'],
    http_req_failed: ['rate<0.001'],
    checks: ['rate>0.999'],
};

export const options =
    PROFILE === 'latency4' || PROFILE === 'latency8' || PROFILE === 'latency16'
        ? {
              scenarios: { latency: { executor: 'constant-vus', vus: PROFILE === 'latency4' ? 4 : PROFILE === 'latency8' ? 8 : 16, duration: '90s', gracefulStop: '20s' } },
              thresholds: { ...correctnessThresholds, ...LATENCY_SLO },
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
          }
        : PROFILE === 'latency'
        ? {
              scenarios: {
                  latency: {
                      executor: 'ramping-vus',
                      startVUs: 8,
                      stages: [
                          { duration: '15s', target: 32 },
                          { duration: '30s', target: 32 },
                          { duration: '15s', target: 64 },
                          { duration: '30s', target: 64 },
                          { duration: '15s', target: 128 },
                          { duration: '30s', target: 128 },
                      ],
                      gracefulStop: '20s',
                  },
              },
              thresholds: { ...correctnessThresholds, ...LATENCY_SLO },
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
          }
        : {
              scenarios: {
                  saturation: {
                      executor: 'constant-vus',
                      vus: VUS,
                      duration: DURATION,
                      gracefulStop: '45s',
                  },
              },
              thresholds: correctnessThresholds,
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              // Beban jenuh memang memaksa antrean panjang; batalkan hanya bila benar-benar macet.
              setupTimeout: '5m',
          };

function auth(token, extra) {
    return { headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...extra } };
}

function post(tenant, resource, body, key, params) {
    return http.post(`${BASE}/api/v1/${resource}`, JSON.stringify(body), {
        ...auth(tenant.token, { 'Idempotency-Key': key }),
        tags: { op: 'create', resource },
        ...params,
    });
}

// ---------------------------------------------------------------- setup

export function setup() {
    const created = [];

    // Tahap dibuat terpisah karena anak butuh id induknya. http.batch menjalankan
    // seluruh tenant pada satu tahap secara paralel supaya setup tetap singkat.
    // 200 sama sahnya dengan 201: kunci seed bersifat stabil, jadi menjalankan ulang
    // load test pada database yang sama harus mengembalikan record yang sama, bukan record baru.
    const stage = (label, requests) => {
        const responses = http.batch(requests);
        responses.forEach((response, index) => {
            if (response.status !== 201 && response.status !== 200) {
                fail(`setup ${label} gagal untuk tenant ${index}: ${response.status} ${response.body}`);
            }
        });

        return responses.map((response) => response.json('data.id'));
    };

    const groupIds = stage(
        'entitas-aset',
        TENANTS.map((tenant, index) => [
            'POST',
            `${BASE}/api/v1/group-aset`,
            JSON.stringify({ nama: `Group seed ${index}`, keterangan: 'seed load test' }),
            auth(tenant.token, { 'Idempotency-Key': `seed-${RUN_ID}-group-${index}` }),
        ]),
    );

    const kategoriIds = stage(
        'kategori-aset',
        TENANTS.map((tenant, index) => [
            'POST',
            `${BASE}/api/v1/kategori-aset`,
            JSON.stringify({ nama: `Kategori seed ${index}`, group_aset_id: groupIds[index] }),
            auth(tenant.token, { 'Idempotency-Key': `seed-${RUN_ID}-kategori-${index}` }),
        ]),
    );

    const jenisIds = stage(
        'jenis-aset',
        TENANTS.map((tenant, index) => [
            'POST',
            `${BASE}/api/v1/jenis-aset`,
            JSON.stringify({ nama: `Jenis seed ${index}`, kategori_aset_id: kategoriIds[index] }),
            auth(tenant.token, { 'Idempotency-Key': `seed-${RUN_ID}-jenis-${index}` }),
        ]),
    );

    const entitasIds = stage(
        'entitas-aset',
        TENANTS.map((tenant, index) => [
            'POST',
            `${BASE}/api/v1/entitas-aset`,
            JSON.stringify({ nama: `Entitas seed ${index}`, jenis_aset_id: jenisIds[index] }),
            auth(tenant.token, { 'Idempotency-Key': `seed-${RUN_ID}-entitas-${index}` }),
        ]),
    );

    const assetIds = stage(
        'aset',
        TENANTS.map((tenant, index) => [
            'POST',
            `${BASE}/api/v1/aset`,
            JSON.stringify({ legal_entity_id: tenant.legalEntityId, usage_org_unit_id: tenant.orgUnitId, jenis_aset_id: jenisIds[index], acquired_on: '2026-01-01', acquisition_value: 1000000, currency_code: 'IDR' }),
            auth(tenant.token, { 'Idempotency-Key': `seed-${RUN_ID}-aset-${index}` }),
        ]),
    );

    TENANTS.forEach((tenant, index) => {
        created.push({
            id: tenant.id,
            token: tenant.token,
            entitasAsetId: entitasIds[index],
            groupAsetId: groupIds[index],
            kategoriAsetId: kategoriIds[index],
            jenisAsetId: jenisIds[index],
            legalEntityId: tenant.legalEntityId,
            orgUnitId: tenant.orgUnitId,
            satuanId: tenant.satuanId,
            assetId: assetIds[index],
        });
    });

    console.log(`setup: ${created.length} tenant siap, masing-masing dengan rantai group/kategori/jenis/entitas`);

    return { tenants: created, narrow: NARROW };
}

// ---------------------------------------------------------------- operations

function record(response, latency, expected, label) {
    latency.add(response.timings.duration);
    if (response.status >= 500) {
        serverErrors.add(1, { label });
    }
    if (response.status === 0) {
        timeouts.add(1, { label });
    }

    return check(response, { [label]: (r) => r.status === expected });
}

function violation(kind, tags = {}) {
    violations.add(1, { kind, ...tags });
    console.error(`correctness violation: ${kind}`);
}

function listMaster(tenant) {
    const resources = Object.keys(CHAINED).concat(STANDALONE, ['entitas-aset']);
    const resource = resources[Math.floor(Math.random() * resources.length)];
    const chained = CHAINED[resource];
    const query = chained ? `?per_page=20&${chained.parentField}=${tenant[chained.seed]}` : '?per_page=20';

    const response = http.get(`${BASE}/api/v1/${resource}${query}`, {
        ...auth(tenant.token),
        tags: { op: 'list', resource },
    });
    record(response, readLatency, 200, 'list 200');

    // Daftar tidak boleh memuat satu pun record milik tenant lain. Filter induk juga
    // harus benar-benar menyaring, bukan sekadar diterima lalu diabaikan.
    if (response.status === 200) {
        const rows = response.json('data') || [];
        for (const row of rows) {
            if (KODE_PREFIX[resource] && !String(row.kode).startsWith(KODE_PREFIX[resource])) {
                violation('wrong_sequence_prefix', { resource });
            }
            if (chained && row[chained.parentField] !== tenant[chained.seed]) {
                violation('parent_filter_ignored', { resource });
            }
        }
    }
}

function showMaster(tenant) {
    const response = http.get(`${BASE}/api/v1/kategori-aset/${tenant.kategoriAsetId}`, {
        ...auth(tenant.token),
        tags: { op: 'show', resource: 'kategori-aset' },
    });
    record(response, readLatency, 200, 'show 200');

    if (response.status === 200 && response.json('data.group_aset.id') !== tenant.groupAsetId) {
        violation('wrong_parent_summary');
    }
}

function createMaster(tenant) {
    const pool = Object.keys(CHAINED).concat(STANDALONE);
    const resource = pool[Math.floor(Math.random() * pool.length)];
    const chained = CHAINED[resource];
    const key = `vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}-${resource}`;
    const body = { nama: `${resource} ${key}`, keterangan: 'load test' };
    if (chained) {
        body[chained.parentField] = tenant[chained.seed];
    }

    const response = post(tenant, resource, body, key);
    record(response, writeLatency, 201, 'create 201');

    if (response.status === 201 && !String(response.json('data.kode')).startsWith(KODE_PREFIX[resource])) {
        violation('wrong_sequence_prefix_on_create', { resource });
    }
}

function updateMaster(tenant) {
    const response = http.patch(
        `${BASE}/api/v1/kategori-aset/${tenant.kategoriAsetId}`,
        JSON.stringify({ keterangan: `disentuh vu${exec.vu.idInTest}` }),
        { ...auth(tenant.token), tags: { op: 'update', resource: 'kategori-aset' } },
    );
    record(response, writeLatency, 200, 'update 200');
}

/** Dua permintaan identik berbarengan harus menghasilkan tepat satu record. */
function idempotencyRace(tenant) {
    const key = `race-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
    const body = JSON.stringify({ nama: `race ${key}` });
    const params = { ...auth(tenant.token, { 'Idempotency-Key': key }), tags: { op: 'race', resource: 'group-aset' } };

    const [first, second] = http.batch([
        ['POST', `${BASE}/api/v1/group-aset`, body, params],
        ['POST', `${BASE}/api/v1/group-aset`, body, params],
    ]);

    [first, second].forEach((response) => writeLatency.add(response.timings.duration));
    const ok = (response) => response.status >= 200 && response.status < 300;
    const bothOk = check({ first, second }, { 'race keduanya 2xx': () => ok(first) && ok(second) });

    if (!bothOk) {
        if (first.status >= 500 || second.status >= 500) {
            serverErrors.add(1, { label: 'race' });
        }
        if (first.status === 0 || second.status === 0) {
            timeouts.add(1, { label: 'race' });
        }

        return;
    }

    if (first.json('data.id') !== second.json('data.id')) {
        violation('idempotency_produced_two_records');
    }
    if (first.status === 200 || second.status === 200) {
        idempotencyReplays.add(1);
    }
}

/** Token tenant A tidak boleh menyentuh data tenant B, baca maupun tulis. */
function crossTenantProbe(tenant, victim) {
    crossTenantProbes.add(1);

    const read = http.get(`${BASE}/api/v1/kategori-aset/${victim.kategoriAsetId}`, {
        ...auth(tenant.token),
        tags: { op: 'probe_read', resource: 'kategori-aset' },
        responseCallback: http.expectedStatuses(404),
    });
    check(read, { 'baca lintas tenant 404': (r) => r.status === 404 });
    if (read.status === 200) {
        violation('cross_tenant_read');
    }

    const write = post(
        tenant,
        'entitas-aset',
        { nama: 'entitas curian', jenis_aset_id: victim.jenisAsetId },
        `steal-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`,
        { tags: { op: 'probe_write', resource: 'entitas-aset' }, responseCallback: http.expectedStatuses(422) },
    );
    check(write, { 'tulis induk lintas tenant 422': (r) => r.status === 422 });
    if (write.status === 201) {
        violation('cross_tenant_parent_accepted');
    }

    const plan = post(tenant, 'perencanaan-aset', planningBody(tenant, victim.jenisAsetId), `steal-plan-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`, { tags: { op: 'probe_write', resource: 'perencanaan-aset' }, responseCallback: http.expectedStatuses(422) });
    check(plan, { 'rencana dengan jenis aset tenant lain 422': (r) => r.status === 422 });
    if (plan.status === 201) violation('cross_tenant_plan_type_accepted');
}

/** Hak pada satu master tidak boleh merembet ke master lain, termasuk saat sistem jenuh. */
function permissionScopeProbe(narrow) {
    scopeProbes.add(1);

    const allowed = http.get(`${BASE}/api/v1/group-aset?per_page=1`, {
        ...auth(narrow.token),
        tags: { op: 'probe_scope_allowed', resource: 'group-aset' },
    });
    check(allowed, { 'scope: group-aset read 200': (r) => r.status === 200 });

    const denied = http.get(`${BASE}/api/v1/kategori-aset?per_page=1`, {
        ...auth(narrow.token),
        tags: { op: 'probe_scope_denied', resource: 'kategori-aset' },
        responseCallback: http.expectedStatuses(403),
    });
    check(denied, { 'scope: kategori-aset read 403': (r) => r.status === 403 });
    if (denied.status === 200) {
        violation('permission_scope_escalation');
    }
}

function lifecycleTransaction(tenant) {
    const key = `plan-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
    const response = post(tenant, 'perencanaan-aset', planningBody(tenant), key);
    record(response, writeLatency, 201, 'planning create 201');
    if (response.status === 201 && !String(response.json('data.kode')).startsWith('PLNA')) violation('wrong_sequence_prefix_on_plan');
}

function planningBody(tenant, jenisAsetId = tenant.jenisAsetId) {
    return { legal_entity_id: tenant.legalEntityId, planning_org_unit_id: tenant.orgUnitId, planned_on: '2026-01-01', planning_year: 2026, planning_type: 'regular', funding_source: 'load test', description: 'load test planning', details: [{ jenis_aset_id: jenisAsetId, satuan_id: tenant.satuanId, quantity: 1, requested_specification: 'RAM 16 GB', estimated_unit_price: 1000000 }] };
}

function planningIdempotencyRace(tenant) {
    const key = `plan-race-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
    const body = JSON.stringify(planningBody(tenant));
    const params = { ...auth(tenant.token, { 'Idempotency-Key': key }), tags: { op: 'race', resource: 'perencanaan-aset' } };
    const [first, second] = http.batch([['POST', `${BASE}/api/v1/perencanaan-aset`, body, params], ['POST', `${BASE}/api/v1/perencanaan-aset`, body, params]]);
    [first, second].forEach((response) => writeLatency.add(response.timings.duration));
    const bothOk = check({ first, second }, { 'race rencana keduanya 2xx': () => first.status >= 200 && first.status < 300 && second.status >= 200 && second.status < 300 });
    if (!bothOk) { if (first.status >= 500 || second.status >= 500) serverErrors.add(1, { label: 'plan-race' }); return; }
    if (first.json('data.id') !== second.json('data.id')) violation('plan_idempotency_produced_two_records');
}

function mutateAsset(tenant) {
    const response = http.post(`${BASE}/api/v1/aset/${tenant.assetId}/penempatan`, JSON.stringify({ effective_on: '2026-01-02', reason: 'load test mutasi', usage_org_unit_id: tenant.orgUnitId }), { ...auth(tenant.token), tags: { op: 'mutate', resource: 'aset' } });
    record(response, writeLatency, 200, 'mutate 200');
}

// ---------------------------------------------------------------- vu loop

export default function (data) {
    const tenants = data.tenants;
    const tenant = tenants[Math.floor(Math.random() * tenants.length)];
    const roll = Math.random();

    if (roll < 0.35) {
        listMaster(tenant);
    } else if (roll < 0.5) {
        showMaster(tenant);
    } else if (roll < 0.68) {
        createMaster(tenant);
    } else if (roll < 0.77) {
        updateMaster(tenant);
    } else if (roll < 0.84) {
        lifecycleTransaction(tenant);
    } else if (roll < 0.89) {
        mutateAsset(tenant);
    } else if (roll < 0.92) {
        idempotencyRace(tenant);
    } else if (roll < 0.94) {
        planningIdempotencyRace(tenant);
    } else if (roll < 0.98) {
        let victim = tenants[Math.floor(Math.random() * tenants.length)];
        if (victim.id === tenant.id) {
            victim = tenants[(tenants.indexOf(tenant) + 1) % tenants.length];
        }
        crossTenantProbe(tenant, victim);
    } else {
        permissionScopeProbe(data.narrow);
    }
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];

        return value === undefined ? null : Number(value.toFixed(2));
    };

    const summary = {
        profile: PROFILE,
        vus_configured: PROFILE === 'latency' ? 128 : PROFILE === 'latency16' ? 16 : PROFILE === 'latency8' ? 8 : PROFILE === 'latency4' ? 4 : VUS,
        tenants: TENANTS.length,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: data.metrics.iterations?.values?.count ?? 0,
        requests: data.metrics.http_reqs?.values?.count ?? 0,
        throughput_rps: metric('http_reqs', 'rate'),
        http_req_failed_rate: metric('http_req_failed', 'rate'),
        checks_rate: metric('checks', 'rate'),
        correctness_violations: data.metrics.correctness_violations?.values?.count ?? 0,
        server_errors: data.metrics.server_errors?.values?.count ?? 0,
        idempotency_replays: data.metrics.idempotency_replays?.values?.count ?? 0,
        client_timeouts: data.metrics.client_timeouts?.values?.count ?? 0,
        cross_tenant_probes: data.metrics.cross_tenant_probes?.values?.count ?? 0,
        permission_scope_probes: data.metrics.permission_scope_probes?.values?.count ?? 0,
        read: { p50: metric('op_read', 'med'), p95: metric('op_read', 'p(95)'), p99: metric('op_read', 'p(99)'), max: metric('op_read', 'max') },
        write: { p50: metric('op_write', 'med'), p95: metric('op_write', 'p(95)'), p99: metric('op_write', 'p(99)'), max: metric('op_write', 'max') },
        thresholds_failed: Object.entries(data.metrics)
            .filter(([, value]) => value.thresholds && Object.values(value.thresholds).some((t) => t.ok === false))
            .map(([name]) => name),
    };

    return {
        stdout: `\n===== RINGKASAN LOAD TEST (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-${PROFILE}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
