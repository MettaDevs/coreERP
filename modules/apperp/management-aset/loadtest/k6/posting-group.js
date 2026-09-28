// Load test posting group aset (feed posting finance, area 8), pada runtime Core yang sungguhan.
//
// Posting group menentukan ke akun mana jurnal aset dicatat. Satu baris diidentifikasi pasangan
// group dan `effective_from`, dan disimpan lewat `PUT` ke alamat pasangan itu tanpa kunci
// idempotensi. Yang dijaga berkas ini:
//
//   PROFILE=race        Yang paling penting. VU dipusatkan pada sedikit group dan menulis ke
//                       tanggal yang SAMA pada detik yang sama. Dua penyimpanan pertama untuk
//                       satu tanggal sama-sama belum melihat baris lawannya; tanpa kunci pada
//                       baris group, yang kalah menabrak indeks unik dan dijawab 500. Bersamaan
//                       dengan itu VU lain mengarsipkan dan membuat ulang satu tanggal tetap,
//                       sehingga indeks unik parsial ikut berebut. Setiap baris yang terbaca
//                       balik wajib persis himpunan A atau B, bukan campuran.
//
//   PROFILE=saturation  Beban serentak pada matriks, pemilih akun, dan penyimpanan, tersebar ke
//                       banyak tenant. Yang digate: KEBENARAN — tidak ada 5xx aplikasi, tidak
//                       ada tulis lintas tenant, tidak ada akun tenant lain yang diterima.
//
// Akun disiapkan lewat impor CSV daftar akun milik Core, jalur yang sama dengan owner di layar,
// bukan disuntik ke database.

import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { siapkanTenant, paramsUntuk, bangunJar, tenantVu, urlModule, BASE, RUN_ID } from '../lib.js';

const PROFILE = __ENV.PROFILE || 'race';
const VUS = Number(__ENV.VUS || 32);
const DURATION = __ENV.DURATION || '90s';
// Konkurensi sengaja dipusatkan pada sedikit tenant: menyebar beban membuat dua penulis hampir
// tidak pernah bertemu pada tanggal yang sama, dan run kembali hijau tanpa membuktikan apa pun.
const RACE_TENANTS = Number(__ENV.RACE_TENANTS || 4);
const TENANT_COUNT = PROFILE === 'race' ? RACE_TENANTS : Number(__ENV.TENANTS || 128);

const ASET = (path) => urlModule('management-aset', path);
const API = ASET('posting-group-aset');

// Urutan kolom sama dengan `AssetPostingGroup::ACCOUNTS`.
const KOLOM = [
    'acquisition_account_id',
    'accumulated_depreciation_account_id',
    'depreciation_expense_account_id',
    'payable_account_id',
    'clearing_account_id',
    'input_vat_account_id',
    'opening_balance_offset_account_id',
];

// Tanggal tetap yang diperebutkan penulis dan pengarsip.
const TANGGAL_TETAP = '2029-01-01';
// Tanggal probe akun tenant lain; jawaban yang benar 422, jadi barisnya tidak pernah lahir.
const TANGGAL_PROBE = '2028-01-01';

/*
 * Mode pembuktian oracle. Yang dirusak adalah permintaan, bukan produknya:
 * - penulis mengirim CAMPURAN himpunan A dan B, sehingga pembacaan balik bukan A maupun B —
 *   `posting_group_mixed_rows` wajib naik;
 * - probe lintas tenant diarahkan ke group milik sendiri, dan probe akun memakai akun milik
 *   sendiri — `correctness_violations` wajib naik.
 * Kalau salah satunya tetap nol pada run seperti ini, pembandingnya mati dan run hijau tidak
 * berarti apa-apa.
 */
const SELFTEST = __ENV.SELFTEST === '1';

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
const timeouts = new Counter('client_timeouts');
// Baris yang terbaca balik bukan salah satu himpunan yang dikirim.
const mixedRows = new Counter('posting_group_mixed_rows');
const rowsChecked = new Counter('posting_group_rows_checked');
// Bukti bahwa balapan pembuatan benar-benar terjadi: PUT ke tanggal baru yang dijawab 200 berarti
// VU lain sudah membuat baris itu lebih dulu pada detik yang sama.
const createsWon = new Counter('race_creates_won');
const createsLost = new Counter('race_creates_lost');
const archives = new Counter('race_archives');

const correctnessThresholds = {
    correctness_violations: ['count==0'],
    posting_group_mixed_rows: ['count==0'],
    server_errors: ['count==0'],
};

const umum = {
    thresholds: correctnessThresholds,
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    setupTimeout: '15m',
    // Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per host.
    batch: 64,
    batchPerHost: 32,
};

export const options =
    PROFILE === 'saturation'
        ? { ...umum, scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '45s' } } }
        : { ...umum, scenarios: { race: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '20s' } } };

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
        console.error(`5xx ${response.request.method} ${response.url}: ${String(response.body).slice(0, 300)}`);
    }

    return response;
}

function csvAkun() {
    const baris = ['external_id,code,name,type,active'];

    for (const himpunan of ['A', 'B']) {
        for (let urutan = 1; urutan <= KOLOM.length; urutan++) {
            baris.push(`pg-${himpunan.toLowerCase()}${urutan},PG-${himpunan}${urutan},Akun uji beban ${himpunan}${urutan},balance_sheet,true`);
        }
    }

    return `${baris.join('\r\n')}\r\n`;
}

function wajibBatch(label, requests, statusSah) {
    const responses = http.batch(requests);
    responses.forEach((response, index) => {
        if (!statusSah.includes(response.status)) {
            fail(`setup ${label} gagal pada tenant ${index}: ${response.status} ${String(response.body).slice(0, 400)}`);
        }
    });

    return responses;
}

export function setup() {
    const tenants = siapkanTenant(TENANT_COUNT, ['management-aset']);
    const jars = tenants.map((tenant) => bangunJar(tenant));
    const csv = csvAkun();

    // 1. Daftar akun lewat impor CSV Core. Menjalankan ulang pada fixture yang sama tidak
    //    mengubah apa pun: baris yang sama dilaporkan tidak berubah.
    const impor = wajibBatch(
        'impor daftar akun',
        jars.map((tenant) => [
            'POST',
            `${BASE}/api/v1/finance-reference-accounts/imports`,
            { file: http.file(csv, 'akun-uji-beban.csv', 'text/csv'), scope: 'all', apply: '1' },
            { jar: tenant.jar, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': tenant.csrf } },
        ]),
        [200],
    );
    impor.forEach((response, index) => {
        if (response.json('data.status') === 'rejected') {
            fail(`setup impor akun ditolak pada tenant ${index}: ${String(response.body).slice(0, 400)}`);
        }
    });

    // 2. Id akun, dibaca lewat pemilih akun modul — jalur yang sama dengan layar.
    const himpunan = (label) =>
        wajibBatch(
            `akun ${label}`,
            jars.map((tenant) => ['GET', `${API}/akun?q=PG-${label}`, null, paramsUntuk(tenant)]),
            [200],
        ).map((response, index) => {
            const akun = (response.json('data') || []).slice().sort((kiri, kanan) => String(kiri.code).localeCompare(String(kanan.code)));

            if (akun.length !== KOLOM.length) {
                fail(`setup akun ${label} tenant ${index}: ${akun.length} akun, bukan ${KOLOM.length}`);
            }

            return akun.map((item) => String(item.id));
        });
    const setA = himpunan('A');
    const setB = himpunan('B');

    // 3. Satu group per tenant. Kode dan kunci stabil per RUN_ID: run baru memakai group baru,
    //    jadi riwayat tanggal berlakunya mulai kosong. Kode group diketik, bukan dari Number Sequence.
    const kode = `PG-${RUN_ID}`.toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/-+$/, '').slice(0, 20);
    const groups = wajibBatch(
        'group aset',
        jars.map((tenant, index) => [
            'POST',
            ASET('group-aset'),
            JSON.stringify({ kode, nama: `Group posting ${RUN_ID}-${index}` }),
            paramsUntuk(tenant, {}, { 'Idempotency-Key': `pg-group-${RUN_ID}-${index}` }),
        ]),
        [200, 201],
    );

    const arenas = tenants.map((tenant, index) => ({
        tenantIndex: index,
        groupId: String(groups[index].json('data.id')),
        setA: setA[index],
        setB: setB[index],
    }));

    console.log(`setup: ${tenants.length} tenant, ${arenas.length} group`);

    return { tenants, arenas };
}

// ---------------------------------------------------------------- workload

function tanggal(hari) {
    return new Date(Date.UTC(2030, 0, 1) + hari * 86400000).toISOString().slice(0, 10);
}

function badan(nilai) {
    return JSON.stringify(Object.fromEntries(KOLOM.map((kolom, index) => [kolom, nilai[index]])));
}

function simpan(tenant, groupId, tanggalBerlaku, body, op, extra) {
    return record(
        http.put(`${API}/${groupId}/${tanggalBerlaku}`, body, paramsUntuk(tenant, { tags: { op, resource: 'posting-group-aset' }, ...extra })),
        writeLatency,
        op,
    );
}

function sama(kiri, kanan) {
    return kiri.length === kanan.length && kiri.every((nilai, index) => nilai === kanan[index]);
}

/** Membaca matriks dan memastikan setiap baris group arena persis himpunan A atau B. */
function periksaBalik(tenant, arena) {
    const baca = record(http.get(API, paramsUntuk(tenant, { tags: { op: 'read', resource: 'posting-group-aset' } })), readLatency, 'read');

    check(baca, { 'matriks terbaca': (response) => response.status === 200 });

    if (baca.status !== 200) {
        return;
    }

    const group = (baca.json('data.groups') || []).find((item) => item.id === arena.groupId);

    if (!group) {
        violations.add(1, { kind: 'group_missing_from_matrix' });
        console.error('correctness violation: group_missing_from_matrix');

        return;
    }

    for (const row of group.rows) {
        rowsChecked.add(1);
        const nilai = KOLOM.map((kolom) => row[kolom]);

        if (!sama(nilai, arena.setA) && !sama(nilai, arena.setB)) {
            mixedRows.add(1);
            console.error(`posting_group_mixed_rows: ${row.effective_from} ${JSON.stringify(nilai)}`);
        }
    }
}

/** Tenant lain tidak boleh menulis ke group ini, dan akun tenant lain tidak boleh diterima. */
function probe(tenant, arena, data) {
    const lain = data.arenas[(arena.tenantIndex + 1) % data.arenas.length];

    if (!lain || lain.tenantIndex === arena.tenantIndex) {
        return;
    }

    const sasaran = SELFTEST ? arena.groupId : lain.groupId;
    const tulis = simpan(tenant, sasaran, '2028-06-01', badan(arena.setA), 'probe', { responseCallback: http.expectedStatuses(404) });

    if (tulis.status === 200 || tulis.status === 201) {
        violations.add(1, { kind: 'cross_tenant_write' });
        console.error('correctness violation: cross_tenant_write');
    }

    const akunAsing = SELFTEST ? arena.setA : lain.setA;
    const pinjam = simpan(tenant, arena.groupId, TANGGAL_PROBE, badan(akunAsing), 'probe', { responseCallback: http.expectedStatuses(422) });

    if (pinjam.status === 200 || pinjam.status === 201) {
        violations.add(1, { kind: 'foreign_account_accepted' });
        console.error('correctness violation: foreign_account_accepted');
    }
}

// Tanggal balapan terakhir yang ditulis VU ini. PUT kedua dari VU yang sama pada detik yang sama
// juga dijawab 200, tetapi itu bukan kekalahan balapan; hanya PUT pertama per tanggal yang dihitung.
let tanggalTerakhir = null;

function race(data) {
    const arena = data.arenas[exec.vu.idInTest % data.arenas.length];
    const tenant = tenantVu(data.tenants, arena.tenantIndex);
    const diminta = SELFTEST
        ? [...arena.setA.slice(0, 4), ...arena.setB.slice(4)]
        : exec.vu.idInTest % 2 === 0 ? arena.setA : arena.setB;

    // 1. Balapan pembuatan: seluruh VU arena menulis tanggal baru yang sama pada detik yang sama.
    //    Satu yang pertama dijawab 201, sisanya 200. 500 berarti yang kalah menabrak indeks unik.
    const baru = tanggal(Math.floor(Date.now() / 1000) % 30000);
    const buat = simpan(tenant, arena.groupId, baru, badan(diminta), 'create_race');
    check(buat, { 'simpan tanggal baru diterima': (response) => response.status === 200 || response.status === 201 });

    if (baru !== tanggalTerakhir) {
        tanggalTerakhir = baru;

        if (buat.status === 201) {
            createsWon.add(1);
        } else if (buat.status === 200) {
            createsLost.add(1);
        }
    }

    // 2. Satu tanggal tetap diperebutkan penulis dan pengarsip. Sesudah arsip, penulis berikutnya
    //    membuat baris baru di atas indeks unik parsial.
    if (exec.vu.idInTest % 4 === 1 && exec.vu.iterationInInstance % 3 === 0) {
        const arsip = record(
            http.del(
                `${API}/${arena.groupId}/${TANGGAL_TETAP}`,
                null,
                paramsUntuk(tenant, { tags: { op: 'archive', resource: 'posting-group-aset' }, responseCallback: http.expectedStatuses(204, 404) }),
            ),
            writeLatency,
            'archive',
        );
        check(arsip, { 'arsip dijawab 204 atau 404': (response) => response.status === 204 || response.status === 404 });

        if (arsip.status === 204) {
            archives.add(1);
        }
    } else {
        const ubah = simpan(tenant, arena.groupId, TANGGAL_TETAP, badan(diminta), 'update_race');
        check(ubah, { 'simpan tanggal tetap diterima': (response) => response.status === 200 || response.status === 201 });
    }

    periksaBalik(tenant, arena);

    if (exec.vu.iterationInInstance % 5 === 0) {
        probe(tenant, arena, data);
    }
}

function saturation(data) {
    const arena = data.arenas[exec.vu.idInTest % data.arenas.length];
    const tenant = tenantVu(data.tenants, arena.tenantIndex);
    const diminta = exec.vu.idInTest % 2 === 0 ? arena.setA : arena.setB;

    periksaBalik(tenant, arena);

    const akun = record(http.get(`${API}/akun?q=PG`, paramsUntuk(tenant, { tags: { op: 'search', resource: 'posting-group-aset' } })), readLatency, 'search');
    check(akun, { 'pemilih akun terbaca': (response) => response.status === 200 });

    // Lima tanggal per group: riwayat tetap pendek seperti di dunia nyata, dan VU yang berbagi
    // tenant tetap sesekali bertemu pada tanggal yang sama.
    const tulis = simpan(tenant, arena.groupId, tanggal(exec.vu.iterationInInstance % 5), badan(diminta), 'write');
    check(tulis, { 'simpan diterima': (response) => response.status === 200 || response.status === 201 });

    probe(tenant, arena, data);
}

export default function (data) {
    if (PROFILE === 'saturation') {
        saturation(data);

        return;
    }

    race(data);
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];

        return value === undefined ? null : Number(value.toFixed(2));
    };
    const count = (name) => data.metrics[name]?.values?.count ?? 0;

    const summary = {
        skenario: 'posting-group',
        profile: PROFILE,
        run_id: RUN_ID,
        selftest: SELFTEST,
        vus_configured: VUS,
        tenants: TENANT_COUNT,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: count('iterations'),
        requests: count('http_reqs'),
        throughput_rps: metric('http_reqs', 'rate'),
        kebenaran: {
            correctness_violations: count('correctness_violations'),
            posting_group_mixed_rows: count('posting_group_mixed_rows'),
            posting_group_rows_checked: count('posting_group_rows_checked'),
            server_errors: count('server_errors'),
        },
        balapan: {
            race_creates_won: count('race_creates_won'),
            race_creates_lost: count('race_creates_lost'),
            race_archives: count('race_archives'),
        },
        kapasitas: {
            gateway_errors: count('gateway_errors'),
            client_timeouts: count('client_timeouts'),
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
        stdout: `\n===== RINGKASAN LOAD TEST posting-group (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-posting-group-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
