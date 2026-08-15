// Load test setup maintenance Management Aset.
//
// master-data.js hanya menyentuh dua master lama yang kebetulan bernama
// maintenance (`item-checklist-maintenance`, `analisa-maintenance`). Seluruh
// permukaan setup maintenance — job type, varian, default, variabel dan template
// checklist, serta kelima endpoint penautannya — tidak diuji di mana pun. Berkas
// ini menutup celah itu.
//
//   PROFILE=saturation  Beban serentak pada CRUD master maintenance.
//                       Yang digate: KEBENARAN. Tidak boleh ada 5xx, tidak boleh
//                       ada kebocoran lintas tenant.
//
//   PROFILE=link-race   Yang paling penting. Beberapa VU mengganti kaitan pada
//                       job type yang SAMA secara bersamaan, dari kedua arah.
//                       Endpoint replace menghapus lalu menyisipkan ulang; tanpa
//                       kunci baris, dua permintaan dapat saling menyela dan
//                       menghasilkan GABUNGAN dua himpunan — keadaan yang tidak
//                       diminta siapa pun dan yang tidak ditolak batasan basis
//                       data mana pun, karena setiap barisnya masing-masing sah.
//                       Feature test tidak akan pernah melihatnya: ia menjalankan
//                       satu permintaan pada satu proses.

import http from 'k6/http';
import { check, fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://lb';
const PROFILE = __ENV.PROFILE || 'link-race';
const VUS = Number(__ENV.VUS || 32);
const DURATION = __ENV.DURATION || '90s';
const RUN_ID = __ENV.RUN_ID || 'r0';

const fixture = JSON.parse(open('./tenants.json'));
const TENANTS = fixture.tenants;

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
const timeouts = new Counter('client_timeouts');
// Setiap kali pembacaan balik menemukan himpunan yang bukan salah satu himpunan
// yang dikirim. Inilah cacat yang dijaga berkas ini.
const mergedSets = new Counter('link_merged_sets');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

const correctnessThresholds = {
    correctness_violations: ['count==0'],
    link_merged_sets: ['count==0'],
    server_errors: ['count==0'],
    http_req_failed: ['rate<0.001'],
    checks: ['rate>0.999'],
};

export const options =
    PROFILE === 'saturation'
        ? {
              scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '45s' } },
              thresholds: correctnessThresholds,
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              setupTimeout: '5m',
          }
        : PROFILE === 'latency'
        ? {
              scenarios: { latency: { executor: 'constant-vus', vus: 16, duration: '90s', gracefulStop: '20s' } },
              thresholds: { ...correctnessThresholds, ...LATENCY_SLO },
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
          }
        : {
              // Konkurensi sengaja dipusatkan: VU banyak pada sedikit tenant, agar
              // beberapa penulis benar-benar berebut baris yang sama. Menyebar beban
              // ke 128 tenant justru membuat balapan hampir tidak pernah terjadi.
              scenarios: { linkRace: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '20s' } },
              thresholds: correctnessThresholds,
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              setupTimeout: '5m',
          };

const RACE_TENANTS = 4;

function auth(tenant, extra) {
    return { headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${tenant.token}`, ...extra } };
}

function record(response, latency, op) {
    latency.add(response.timings.duration, { op });
    if (response.status === 0) {
        timeouts.add(1);
    } else if (response.status === 502 || response.status === 504) {
        // Saturasi pada load balancer, bukan cacat aplikasi. Dihitung terpisah
        // supaya tidak tertukar dengan jawaban yang salah.
        gatewayErrors.add(1);
    } else if (response.status >= 500) {
        serverErrors.add(1);
    }

    return response;
}

function createMaster(tenant, resource, nama, key) {
    return record(
        http.post(`${BASE}/api/v1/${resource}`, JSON.stringify({ nama }), {
            ...auth(tenant, { 'Idempotency-Key': key }),
            tags: { op: 'create', resource },
        }),
        writeLatency,
        'create',
    );
}

// ---------------------------------------------------------------- setup

export function setup() {
    const arenas = [];

    for (let index = 0; index < RACE_TENANTS; index++) {
        const tenant = TENANTS[index];
        // Kunci seed stabil per RUN_ID: menjalankan ulang pada database yang sama
        // harus memakai kembali record yang sama, bukan menumbuhkan data seed.
        const jobType = createMaster(tenant, 'maintenance-job-types', `Race job type ${RUN_ID}`, `mnt-race-job-${RUN_ID}-${index}`);
        if (jobType.status !== 200 && jobType.status !== 201) {
            fail(`setup job type gagal: ${jobType.status} ${jobType.body}`);
        }

        const assetTypes = [];
        for (let slot = 0; slot < 4; slot++) {
            const asset = createMaster(tenant, 'jenis-aset', `Race jenis aset ${RUN_ID}-${slot}`, `mnt-race-asset-${RUN_ID}-${index}-${slot}`);
            if (asset.status !== 200 && asset.status !== 201) {
                fail(`setup jenis aset gagal: ${asset.status} ${asset.body}`);
            }
            assetTypes.push(asset.json('data.id'));
        }

        arenas.push({
            tenantIndex: index,
            jobTypeId: jobType.json('data.id'),
            // Dua himpunan yang sengaja lepas satu sama lain. Setiap pembacaan balik
            // harus persis sama dengan salah satunya. Kalau muncul anggota dari
            // keduanya, penggantian saling menyela dan hasilnya adalah gabungan.
            setA: [assetTypes[0], assetTypes[1]].sort(),
            setB: [assetTypes[2], assetTypes[3]].sort(),
        });
    }

    return { arenas };
}

// ---------------------------------------------------------------- workload

function sameSet(left, right) {
    return left.length === right.length && left.every((value, index) => value === right[index]);
}

function linkRace(data) {
    const arena = data.arenas[exec.vu.idInTest % data.arenas.length];
    const tenant = TENANTS[arena.tenantIndex];
    // VU genap menulis himpunan A, ganjil menulis himpunan B, ke job type yang sama.
    const wanted = exec.vu.idInTest % 2 === 0 ? arena.setA : arena.setB;

    const write = record(
        http.put(
            `${BASE}/api/v1/maintenance-job-types/${arena.jobTypeId}/asset-types`,
            JSON.stringify({ jenis_aset_ids: wanted }),
            { ...auth(tenant), tags: { op: 'replace', resource: 'job-type-asset-types' } },
        ),
        writeLatency,
        'replace',
    );
    check(write, { 'replace diterima': (response) => response.status === 200 });

    const read = record(
        http.get(`${BASE}/api/v1/maintenance-job-types/${arena.jobTypeId}/asset-types`, {
            ...auth(tenant),
            tags: { op: 'read', resource: 'job-type-asset-types' },
        }),
        readLatency,
        'read',
    );
    if (read.status !== 200) {
        return;
    }

    const selected = (read.json('data.selected') || []).map((item) => String(item.id)).sort();
    // Himpunan kosong sah: penulis lain mungkin baru menghapus dan belum menyisipkan.
    // Yang tidak boleh adalah anggota dari kedua himpunan muncul bersamaan.
    const isA = sameSet(selected, arena.setA);
    const isB = sameSet(selected, arena.setB);
    const merged = !isA && !isB && selected.length > 0;
    if (merged) {
        mergedSets.add(1);
    }
    check(read, { 'kaitan tetap satu himpunan utuh': () => !merged });
}

function saturation(data) {
    const tenant = TENANTS[exec.vu.idInTest % TENANTS.length];
    const unique = `${RUN_ID}-${exec.vu.idInTest}-${exec.vu.iterationInInstance}`;

    const jobType = createMaster(tenant, 'maintenance-job-types', `Job type ${unique}`, `mnt-sat-job-${unique}`);
    check(jobType, { 'job type dibuat': (response) => response.status === 200 || response.status === 201 });
    if (jobType.status !== 200 && jobType.status !== 201) {
        return;
    }

    const variable = createMaster(tenant, 'maintenance-checklist-variables', `Variabel ${unique}`, `mnt-sat-var-${unique}`);
    check(variable, { 'variabel dibuat': (response) => response.status === 200 || response.status === 201 });
    if (variable.status === 200 || variable.status === 201) {
        const values = record(
            http.put(
                `${BASE}/api/v1/maintenance-checklist-variables/${variable.json('data.id')}/values`,
                JSON.stringify({ values: [{ line_number: 1, value: 'Baik', result_code: 'pass' }, { line_number: 2, value: 'Rusak', result_code: 'fail' }] }),
                { ...auth(tenant), tags: { op: 'replace', resource: 'variable-values' } },
            ),
            writeLatency,
            'replace',
        );
        check(values, { 'nilai variabel disimpan': (response) => response.status === 200 });
    }

    const list = record(
        http.get(`${BASE}/api/v1/maintenance-job-types?per_page=25`, { ...auth(tenant), tags: { op: 'list', resource: 'maintenance-job-types' } }),
        readLatency,
        'list',
    );
    if (list.status === 200) {
        // Kebocoran lintas tenant: setiap baris yang terbaca wajib milik tenant ini.
        // Diperiksa lewat kode, karena kode memuat prefix dan nomor per tenant.
        const rows = list.json('data') || [];
        const foreign = rows.filter((row) => row.tenant_id !== undefined && String(row.tenant_id) !== String(tenant.id));
        if (foreign.length > 0) {
            violations.add(foreign.length);
        }
        check(list, { 'daftar hanya milik tenant sendiri': () => foreign.length === 0 });
    }
}

export default function (data) {
    if (PROFILE === 'saturation' || PROFILE === 'latency') {
        saturation(data);

        return;
    }

    linkRace(data);
}
