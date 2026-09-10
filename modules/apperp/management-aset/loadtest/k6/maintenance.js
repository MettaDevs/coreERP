// Load test setup maintenance Management Aset, pada runtime Core yang sungguhan.
//
// `master-data.js` hanya menyentuh dua master lama yang kebetulan bernama maintenance
// (`item-checklist-maintenance`, `analisa-maintenance`). Seluruh permukaan setup maintenance —
// job type, varian, default, variabel dan template checklist, serta endpoint penautannya —
// tidak diuji di mana pun. Berkas ini menutup celah itu.
//
//   PROFILE=saturation  Beban serentak pada CRUD master maintenance.
//                       Yang digate: KEBENARAN. Tidak boleh ada 5xx aplikasi, tidak boleh ada
//                       kebocoran lintas tenant.
//
//   PROFILE=link-race   Yang paling penting. Beberapa VU mengganti kaitan pada job type yang
//                       SAMA secara bersamaan. Endpoint replace menghapus lalu menyisipkan
//                       ulang; tanpa kunci baris, dua permintaan dapat saling menyela dan
//                       menghasilkan GABUNGAN dua himpunan — keadaan yang tidak diminta
//                       siapa pun dan yang tidak ditolak batasan basis data mana pun, karena
//                       setiap barisnya masing-masing sah. Feature test tidak akan pernah
//                       melihatnya: ia menjalankan satu permintaan pada satu proses.
//
// Sejak F7-03 identitas datang dari sesi Core, bukan token konteks, dan stack-nya
// `apps/control-plane/loadtest/`. Karena satu sesi milik satu pengguna, VU dipetakan tetap ke
// arenanya: VU genap menulis himpunan A, VU ganjil menulis himpunan B, keduanya pada tenant
// dan job type yang sama.

import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { siapkanTenant, paramsUntuk, bangunJar, tenantVu, urlModule, RUN_ID } from '../lib.js';

const PROFILE = __ENV.PROFILE || 'link-race';
const VUS = Number(__ENV.VUS || 32);
const DURATION = __ENV.DURATION || '90s';
// Konkurensi sengaja dipusatkan pada sedikit tenant: menyebar beban ke 128 tenant membuat
// balapan hampir tidak pernah terjadi, dan run kembali hijau tanpa membuktikan apa pun.
const RACE_TENANTS = Number(__ENV.RACE_TENANTS || 4);
const TENANT_COUNT = PROFILE === 'link-race' ? RACE_TENANTS : Number(__ENV.TENANTS || 128);

const ASET = (path) => urlModule('management-aset', path);

/*
 * Mode pembuktian oracle; lihat penjelasan yang sama di `master-data.js`.
 *
 * Di sini yang dirusak adalah nilai yang dikirim: setiap VU menulis GABUNGAN kedua himpunan,
 * sehingga pembacaan balik bukan A maupun B. `link_merged_sets` wajib naik. Kalau ia tetap nol
 * pada run seperti ini, pembandingnya mati dan seluruh run hijau tidak berarti apa-apa.
 */
const SELFTEST = __ENV.SELFTEST === '1';

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
const timeouts = new Counter('client_timeouts');
// Setiap kali pembacaan balik menemukan himpunan yang bukan salah satu himpunan yang dikirim.
// Inilah cacat yang dijaga berkas ini.
const mergedSets = new Counter('link_merged_sets');
const raceReads = new Counter('link_race_reads');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

// Alasan `http_req_failed` dan `checks` tidak menjadi gate: lihat komentar yang sama di
// `master-data.js`. 504 dari load balancer adalah kapasitas, bukan jawaban yang salah.
const correctnessThresholds = {
    correctness_violations: ['count==0'],
    link_merged_sets: ['count==0'],
    server_errors: ['count==0'],
};

export const options =
    PROFILE === 'saturation'
        ? {
              scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '45s' } },
              thresholds: correctnessThresholds,
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              setupTimeout: '15m',
              // Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per
              // host, dan dengan 128 tenant itu membuat setup lebih lama daripada run-nya.
              batch: 64,
              batchPerHost: 32,
          }
        : PROFILE === 'latency'
        ? {
              scenarios: { latency: { executor: 'constant-vus', vus: Number(__ENV.LATENCY_VUS || 16), duration: DURATION, gracefulStop: '20s' } },
              thresholds: { ...correctnessThresholds, ...LATENCY_SLO },
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              setupTimeout: '15m',
              // Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per
              // host, dan dengan 128 tenant itu membuat setup lebih lama daripada run-nya.
              batch: 64,
              batchPerHost: 32,
          }
        : {
              scenarios: { linkRace: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '20s' } },
              thresholds: correctnessThresholds,
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              setupTimeout: '15m',
              // Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per
              // host, dan dengan 128 tenant itu membuat setup lebih lama daripada run-nya.
              batch: 64,
              batchPerHost: 32,
          };

// ---------------------------------------------------------------- setup

function record(response, latency, op) {
    latency.add(response.timings.duration, { op });

    if (response.status === 0) {
        timeouts.add(1);
    } else if (response.status === 502 || response.status === 504) {
        // Saturasi pada load balancer, bukan cacat aplikasi.
        gatewayErrors.add(1);
    } else if (response.status >= 500) {
        serverErrors.add(1);
    }

    return response;
}

function buatMaster(tenant, resource, nama, kunci) {
    return record(
        http.post(ASET(resource), JSON.stringify({ nama }), paramsUntuk(tenant, { tags: { op: 'create', resource } }, { 'Idempotency-Key': kunci })),
        writeLatency,
        'create',
    );
}

export function setup() {
    const tenants = siapkanTenant(TENANT_COUNT, ['management-aset']);
    const arenas = [];

    for (let index = 0; index < Math.min(RACE_TENANTS, tenants.length); index++) {
        const tenant = bangunJar(tenants[index]);
        // Kunci seed stabil per RUN_ID: menjalankan ulang pada database yang sama memakai
        // kembali record yang sama, bukan menumbuhkan data seed.
        const jobType = buatMaster(tenant, 'maintenance-job-types', `Race job type ${RUN_ID}`, `mnt-race-job-${RUN_ID}-${index}`);

        if (jobType.status !== 200 && jobType.status !== 201) {
            fail(`setup job type gagal: ${jobType.status} ${String(jobType.body).slice(0, 300)}`);
        }

        const jenisAset = [];

        for (let slot = 0; slot < 4; slot++) {
            const aset = buatMaster(tenant, 'jenis-aset', `Race jenis aset ${RUN_ID}-${slot}`, `mnt-race-asset-${RUN_ID}-${index}-${slot}`);

            if (aset.status !== 200 && aset.status !== 201) {
                fail(`setup jenis aset gagal: ${aset.status} ${String(aset.body).slice(0, 300)}`);
            }

            jenisAset.push(aset.json('data.id'));
        }

        arenas.push({
            tenantIndex: index,
            jobTypeId: jobType.json('data.id'),
            // Dua himpunan yang sengaja lepas satu sama lain. Setiap pembacaan balik harus
            // persis sama dengan salah satunya. Kalau muncul anggota dari keduanya,
            // penggantian saling menyela dan hasilnya adalah gabungan.
            setA: [jenisAset[0], jenisAset[1]].sort(),
            setB: [jenisAset[2], jenisAset[3]].sort(),
        });
    }

    console.log(`setup: ${tenants.length} tenant, ${arenas.length} arena balapan`);

    return { tenants, arenas };
}

// ---------------------------------------------------------------- workload

function himpunanSama(kiri, kanan) {
    return kiri.length === kanan.length && kiri.every((nilai, index) => nilai === kanan[index]);
}

function linkRace(data) {
    const arena = data.arenas[exec.vu.idInTest % data.arenas.length];
    const tenant = tenantVu(data.tenants, arena.tenantIndex);
    // VU genap menulis himpunan A, ganjil menulis himpunan B, ke job type yang sama.
    const diminta = SELFTEST
        ? [...arena.setA, ...arena.setB].sort()
        : exec.vu.idInTest % 2 === 0 ? arena.setA : arena.setB;

    const tulis = record(
        http.put(
            `${ASET('maintenance-job-types')}/${arena.jobTypeId}/asset-types`,
            JSON.stringify({ jenis_aset_ids: diminta }),
            paramsUntuk(tenant, { tags: { op: 'replace', resource: 'job-type-asset-types' } }),
        ),
        writeLatency,
        'replace',
    );
    check(tulis, { 'replace diterima': (response) => response.status === 200 });

    const baca = record(
        http.get(`${ASET('maintenance-job-types')}/${arena.jobTypeId}/asset-types`, paramsUntuk(tenant, { tags: { op: 'read', resource: 'job-type-asset-types' } })),
        readLatency,
        'read',
    );

    if (baca.status !== 200) {
        return;
    }

    raceReads.add(1);
    const terpilih = (baca.json('data.selected') || []).map((item) => String(item.id)).sort();
    // Himpunan kosong sah: penulis lain mungkin baru menghapus dan belum menyisipkan. Yang
    // tidak boleh adalah anggota dari kedua himpunan muncul bersamaan.
    const isA = himpunanSama(terpilih, arena.setA);
    const isB = himpunanSama(terpilih, arena.setB);
    const gabungan = !isA && !isB && terpilih.length > 0;

    if (gabungan) {
        mergedSets.add(1);
        console.error(`link_merged_sets: ${JSON.stringify(terpilih)}`);
    }

    check(baca, { 'kaitan tetap satu himpunan utuh': () => !gabungan });
}

function saturation(data) {
    const tenant = tenantVu(data.tenants, exec.vu.idInTest);
    const unik = `${RUN_ID}-${exec.vu.idInTest}-${exec.vu.iterationInInstance}`;

    const jobType = buatMaster(tenant, 'maintenance-job-types', `Job type ${unik}`, `mnt-sat-job-${unik}`);
    check(jobType, { 'job type dibuat': (response) => response.status === 200 || response.status === 201 });

    if (jobType.status !== 200 && jobType.status !== 201) {
        return;
    }

    if (!String(jobType.json('data.kode')).startsWith('JPMA')) {
        violations.add(1, { kind: 'wrong_sequence_prefix_job_type' });
    }

    const variabel = buatMaster(tenant, 'maintenance-checklist-variables', `Variabel ${unik}`, `mnt-sat-var-${unik}`);
    check(variabel, { 'variabel dibuat': (response) => response.status === 200 || response.status === 201 });

    if (variabel.status === 200 || variabel.status === 201) {
        const nilai = record(
            http.put(
                `${ASET('maintenance-checklist-variables')}/${variabel.json('data.id')}/values`,
                JSON.stringify({ values: [{ line_number: 1, value: 'Baik', result_code: 'pass' }, { line_number: 2, value: 'Rusak', result_code: 'fail' }] }),
                paramsUntuk(tenant, { tags: { op: 'replace', resource: 'variable-values' } }),
            ),
            writeLatency,
            'replace',
        );
        check(nilai, { 'nilai variabel disimpan': (response) => response.status === 200 });
    }

    const daftar = record(
        http.get(`${ASET('maintenance-job-types')}?per_page=25`, paramsUntuk(tenant, { tags: { op: 'list', resource: 'maintenance-job-types' } })),
        readLatency,
        'list',
    );

    if (daftar.status === 200) {
        // Master tidak memuat `tenant_id` di payload, jadi kebocoran diperiksa dua arah:
        // prefix nomor di sini, dan probe baca lintas tenant di bawah. Oracle SQL sesudah run
        // memeriksa hal yang sama langsung pada tabel.
        const rows = daftar.json('data') || [];
        const asing = rows.filter((row) => !String(row.kode).startsWith('JPMA'));

        if (asing.length > 0) {
            violations.add(asing.length, { kind: 'wrong_sequence_prefix_in_list' });
        }
    }

    // Job type milik tenant sebelah tidak boleh terbaca, juga saat sistem jenuh.
    const korban = data.tenants[(tenant.index + 1) % data.tenants.length];

    if (korban && korban.index !== tenant.index) {
        const curi = record(
            http.get(`${ASET('maintenance-job-types')}/${jobType.json('data.id')}`, {
                ...paramsUntuk(tenantVu(data.tenants, korban.index), { tags: { op: 'probe_read', resource: 'maintenance-job-types' } }),
                responseCallback: http.expectedStatuses(404),
            }),
            readLatency,
            'probe',
        );

        if (curi.status === 200) {
            violations.add(1, { kind: 'cross_tenant_read' });
            console.error('correctness violation: cross_tenant_read');
        }
    }
}

export default function (data) {
    if (PROFILE === 'saturation' || PROFILE === 'latency') {
        saturation(data);

        return;
    }

    linkRace(data);
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];

        return value === undefined ? null : Number(value.toFixed(2));
    };

    const summary = {
        skenario: 'maintenance',
        profile: PROFILE,
        run_id: RUN_ID,
        selftest: SELFTEST,
        vus_configured: VUS,
        tenants: TENANT_COUNT,
        race_arenas: PROFILE === 'link-race' ? RACE_TENANTS : 0,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: data.metrics.iterations?.values?.count ?? 0,
        requests: data.metrics.http_reqs?.values?.count ?? 0,
        throughput_rps: metric('http_reqs', 'rate'),
        kebenaran: {
            correctness_violations: data.metrics.correctness_violations?.values?.count ?? 0,
            link_merged_sets: data.metrics.link_merged_sets?.values?.count ?? 0,
            link_race_reads: data.metrics.link_race_reads?.values?.count ?? 0,
            server_errors: data.metrics.server_errors?.values?.count ?? 0,
        },
        kapasitas: {
            gateway_errors: data.metrics.gateway_errors?.values?.count ?? 0,
            client_timeouts: data.metrics.client_timeouts?.values?.count ?? 0,
            http_req_failed_rate: metric('http_req_failed', 'rate'),
            checks_rate: metric('checks', 'rate'),
        },
        read: { p50: metric('op_read', 'med'), p95: metric('op_read', 'p(95)'), p99: metric('op_read', 'p(99)'), max: metric('op_read', 'max') },
        write: { p50: metric('op_write', 'med'), p95: metric('op_write', 'p(95)'), p99: metric('op_write', 'p(99)'), max: metric('op_write', 'max') },
        thresholds_failed: Object.entries(data.metrics)
            .filter(([, value]) => value.thresholds && Object.values(value.thresholds).some((t) => t.ok === false))
            .map(([name]) => name),
    };

    return {
        stdout: `\n===== RINGKASAN LOAD TEST maintenance (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-maintenance-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
