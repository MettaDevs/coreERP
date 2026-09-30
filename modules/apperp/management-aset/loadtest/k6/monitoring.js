// Load test monitoring aset (pemeriksaan fisik) Management Aset, pada runtime Core yang sungguhan.
//
// Bentuknya sama dengan `work-order.js`: tenant disiapkan lewat alur pendaftaran usaha di dalam
// `setup()`, identitasnya cookie sesi Core, dan rutenya berawalan `/api/modules/management-aset/v1/`.
//
// Dua profil, karena permukaan ini punya dua pertanyaan berbeda:
//
//   PROFILE=saturation  Beban serentak pada seluruh siklus monitoring: buat, isi otomatis, catat
//                       temuan, selesaikan, daftar, rincian, dan pratinjau laporan. Yang digate:
//                       KEBENARAN — tidak ada 5xx aplikasi, kebocoran lintas tenant, eskalasi hak,
//                       dokumen selesai yang masih dapat diubah, idempotency yang pecah, atau hasil
//                       baris yang menyimpang dari aturan.
//
//   PROFILE=race        Banyak VU menulis dokumen yang SAMA dari versi yang sama: PATCH dengan dua
//                       himpunan baris yang berbeda, isi otomatis, dan penyelesaian. Tepat satu
//                       boleh menang per versi; himpunan baris yang terbaca kembali wajib salah satu
//                       yang dikirim, tidak pernah gabungan keduanya. Dokumennya dipilih menurut
//                       jendela waktu, bukan menurut VU, supaya VU yang berbeda benar-benar bertemu
//                       pada dokumen yang sama.
//
// Oracle SQL-nya di `verify.sql`: nomor ganda per entitas legal, baris lintas tenant, baris aset
// ganda, hasil yang menyimpang dari aturan, penyelesaian ganda, dan register aset yang berubah karena
// monitoring. Aset skenario ini bernama `LT-MON …` dan tidak disentuh skenario lain, supaya oracle
// "register tidak berubah" punya subjek yang hanya boleh dibaca monitoring.

import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { FIXTURE, RUN_ID, bangunJar, paramsUntuk, sempitkanTenant, siapkanTenant, tenantVu, urlModule } from '../lib.js';
import { kodeManual, lahirkanAset } from './seed-aset.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const DURATION = __ENV.DURATION || '90s';
// Balapan dipusatkan pada sedikit tenant dan sedikit dokumen: disebar, dua penulis hampir tidak
// pernah bertemu dan run kembali hijau tanpa membuktikan apa pun.
const RACE_TENANTS = Number(__ENV.RACE_TENANTS || 4);
const RACE_DOCS = Number(__ENV.RACE_DOCS || 64);
const TENANT_COUNT = PROFILE === 'race' ? RACE_TENANTS : Number(__ENV.TENANTS || 128);
// Aset per tenant di lokasi pemeriksaan. Empat supaya himpunan A dan B pada balapan tidak saling
// menutup: gabungan keduanya bukan himpunan yang sah.
const ASET_PER_TENANT = 4;
// Prefix nomor diatur tenant di Atur nomor; bawaan manifest untuk tenant baru adalah MONA.
const PREFIX = __ENV.PREFIX || 'MONA';

const ASET = (path) => urlModule('management-aset', path);
const MON = ASET('monitoring-aset');

/*
 * `SELFTEST=1` merusak PERMINTAAN, bukan produknya, supaya pendeteksinya terbukti hidup:
 *
 *   - probe baca lintas tenant diarahkan ke dokumen milik sendiri;
 *   - probe eskalasi hak memakai sesi yang memang berhak penuh;
 *   - probe dokumen terkunci mengubah draf, bukan dokumen selesai;
 *   - balapan idempotency mengirim dua kunci yang berbeda;
 *   - balapan penyimpanan menganggap gabungan himpunan A dan B sebagai satu-satunya yang sah.
 *
 * Kelimanya WAJIB menaikkan pencacah pelanggaran dan membuat run merah.
 */
const SELFTEST = __ENV.SELFTEST === '1';

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
const timeouts = new Counter('client_timeouts');
const crossTenantProbes = new Counter('cross_tenant_probes');
const scopeProbes = new Counter('permission_scope_probes');
const lockedProbes = new Counter('locked_document_probes');
const idempotencyReplays = new Counter('idempotency_replays');
// Dua tulisan dari versi yang sama sama-sama dijawab 200. Inilah cacat yang dijaga profil `race`.
const doubleWins = new Counter('monitoring_double_wins');
const races = new Counter('monitoring_races');
const blendedSets = new Counter('monitoring_blended_line_sets');
const completions = new Counter('monitoring_completions');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

// Gate kebenaran saja; lihat `work-order.js` untuk alasan `http_req_failed` tidak ada di sini.
const correctnessThresholds = {
    correctness_violations: ['count==0'],
    monitoring_double_wins: ['count==0'],
    monitoring_blended_line_sets: ['count==0'],
    server_errors: ['count==0'],
};

const batasBatch = { setupTimeout: '15m', batch: 64, batchPerHost: 32 };
const statistik = ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'];

export const options =
    PROFILE === 'race'
        ? {
              scenarios: { race: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '20s' } },
              thresholds: correctnessThresholds,
              summaryTrendStats: statistik,
              ...batasBatch,
          }
        : PROFILE === 'latency'
          ? {
                scenarios: { latency: { executor: 'constant-vus', vus: Number(__ENV.LATENCY_VUS || 16), duration: DURATION, gracefulStop: '20s' } },
                thresholds: { ...correctnessThresholds, ...LATENCY_SLO },
                summaryTrendStats: statistik,
                ...batasBatch,
            }
          : {
                scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '45s' } },
                thresholds: correctnessThresholds,
                summaryTrendStats: statistik,
                ...batasBatch,
            };

// ---------------------------------------------------------------- setup

function tahap(label, requests, statusSah = [200, 201]) {
    const responses = http.batch(requests);
    responses.forEach((response, index) => {
        if (!statusSah.includes(response.status)) {
            fail(`setup ${label} gagal pada tenant ${index}: ${response.status} ${String(response.body).slice(0, 400)}`);
        }
    });

    return responses;
}

const ids = (responses) => responses.map((response) => response.json('data.id'));

function badan(tenant, extra = {}) {
    return JSON.stringify({
        legal_entity_id: tenant.legalEntityId,
        responsible_org_unit_id: tenant.orgUnitId,
        lokasi_aset_id: tenant.lokasiId,
        tanggal: '2026-08-20',
        keterangan: 'load test monitoring',
        ...extra,
    });
}

export function setup() {
    const tenants = siapkanTenant(TENANT_COUNT, ['management-aset']);
    // Tenant yang sesinya milik anggota berhak satu duty master: wajib ditolak pada monitoring.
    const sempit = sempitkanTenant(siapkanTenant(1, ['management-aset'], 'sempit-mon')[0]);

    const params = (tenant, kunci) => paramsUntuk(tenant, {}, kunci ? { 'Idempotency-Key': kunci } : undefined);
    const semua = (fn) => tenants.map((tenant, index) => fn(bangunJar(tenant), index));
    // Kunci seed diikat ke FIXTURE: run berikutnya pada fixture yang sama memakai data yang sama.
    const kunci = (nama, index) => `mon-${FIXTURE}-${nama}-${index}`;

    const lokasiIds = ids(tahap('lokasi-aset', semua((tenant, index) => ['POST', ASET('lokasi-aset'), JSON.stringify({ nama: `LT-MON lokasi ${index}` }), params(tenant, kunci('lokasi', index))])));
    const groupResponses = tahap('group-aset', semua((tenant, index) => ['POST', ASET('group-aset'), JSON.stringify({ kode: kodeManual('MON', FIXTURE, 'G', index), nama: `LT-MON group ${index}` }), params(tenant, kunci('group', index))]));
    const groupIds = ids(groupResponses);
    const jenisIds = ids(tahap('jenis-aset', semua((tenant, index) => ['POST', ASET('jenis-aset'), JSON.stringify({ nama: `LT-MON jenis ${index}` }), params(tenant, kunci('jenis', index))])));
    const profilIds = ids(tahap('profil-penyusutan', semua((tenant, index) => ['POST', ASET('profil-penyusutan'), JSON.stringify({ nama: `LT-MON profil ${index}`, method: 'straight_line', frequency: 'monthly', year_basis: 'calendar', useful_life_periods: 48, convention: 'full_month' }), params(tenant, kunci('profil', index))])));
    const bukuIds = ids(tahap('buku-penyusutan', semua((tenant, index) => ['POST', ASET('buku-penyusutan'), JSON.stringify({ kode: kodeManual('MON', FIXTURE, 'B', index), nama: `LT-MON buku ${index}`, posting_layer: 'current' }), params(tenant, kunci('buku', index))])));
    tahap(
        'matriks group x buku',
        semua((tenant, index) => [
            'PUT',
            `${ASET('group-aset')}/${groupIds[index]}/buku-penyusutan`,
            // Matriks milik group: versi group dari jawaban pembuatannya (area 3).
            JSON.stringify({ rows: [{ buku_id: bukuIds[index], depreciation_profile_id: profilIds[index], depreciate: false }], version: groupResponses[index].json('data.version') }),
            params(tenant),
        ]),
    );
    // Aset lahir dari penerimaan yang diselesaikan, langsung di lokasi pemeriksaan. Namanya berawalan
    // `LT-MON` supaya oracle register di `verify.sql` tahu aset mana yang hanya disentuh monitoring.
    const asetIds = lahirkanAset(
        tenants.map((tenant) => bangunJar(tenant)),
        { groupIds, jenisIds },
        (index) => kunci('penerimaan', index),
        { lokasiIds, nama: (index) => `LT-MON aset ${index}`, jumlah: ASET_PER_TENANT, semua: true },
    );

    const lengkap = tenants.map((tenant, index) => ({ ...tenant, lokasiId: lokasiIds[index], asetIds: asetIds[index] }));

    // Satu dokumen draf tetap per tenant, untuk probe baca lintas tenant dan pembacaan rincian, dan
    // satu dokumen selesai per tenant, untuk probe dokumen terkunci.
    const seedIds = ids(tahap('monitoring seed', lengkap.map((tenant, index) => ['POST', MON, badan(tenant), params(bangunJar(tenant), kunci(`seed-${RUN_ID}`, index))])));
    const kunciIds = ids(tahap(
        'monitoring terkunci',
        lengkap.map((tenant, index) => ['POST', MON, badan(tenant, { details: tenant.asetIds.map((asetId) => ({ aset_id: asetId, ada: true })) }), params(bangunJar(tenant), kunci(`terkunci-${RUN_ID}`, index))]),
    ));
    tahap(
        'selesaikan monitoring terkunci',
        lengkap.map((tenant, index) => ['POST', `${MON}/${kunciIds[index]}/selesaikan`, JSON.stringify({ version: 1 }), params(bangunJar(tenant))]),
        // 422 pada run ulang dengan RUN_ID yang sama: dokumennya sudah selesai sejak run sebelumnya.
        [200, 422],
    );

    // Dokumen arena balapan: banyak draf per tenant, masing-masing sudah diisi otomatis.
    let raceDocs = lengkap.map(() => []);

    if (PROFILE === 'race') {
        raceDocs = lengkap.map((tenant, index) => {
            const jar = bangunJar(tenant);
            const dibuat = tahap(
                `arena balapan tenant ${index}`,
                Array.from({ length: RACE_DOCS }, (_, nomor) => ['POST', MON, badan(tenant), params(jar, `mon-${RUN_ID}-arena-${index}-${nomor}`)]),
            );

            return dibuat.map((response) => response.json('data.id'));
        });
    }

    console.log(`setup: ${lengkap.length} tenant siap, ${ASET_PER_TENANT} aset per tenant, + 1 tenant berhak sempit`);

    return {
        tenants: lengkap.map((tenant, index) => ({ ...tenant, seedId: seedIds[index], lockedId: kunciIds[index], raceDocs: raceDocs[index] })),
        sempit,
    };
}

// ---------------------------------------------------------------- operasi

function recordFailure(response, label) {
    if (response.status === 0) {
        timeouts.add(1, { label });
    } else if (response.status === 502 || response.status === 504) {
        gatewayErrors.add(1, { label });
    } else if (response.status >= 500) {
        serverErrors.add(1, { label });
    }
}

function record(response, latency, expected, label) {
    latency.add(response.timings.duration);
    recordFailure(response, label);

    return check(response, { [label]: (r) => r.status === expected });
}

function violation(kind, tags = {}) {
    violations.add(1, { kind, ...tags });
    console.error(`correctness violation: ${kind} ${JSON.stringify(tags)}`);
}

const kunciIterasi = (nama) => `mon-${RUN_ID}-${nama}-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
const tidakTersedia = (response) => response.status === 0 || response.status === 502 || response.status === 504;
const himpunan = (details) => (details || []).map((baris) => String(baris.aset_id)).sort().join(',');

/**
 * Siklus penuh: buat, isi otomatis, catat temuan, selesaikan. Seluruh aset tenant aktif, jadi
 * temuan "ada" wajib menghasilkan `sesuai` di setiap baris, dan "tidak ada" `tidak_sesuai`.
 */
function siklus(tenant) {
    const dibuat = http.post(MON, badan(tenant), paramsUntuk(tenant, { tags: { op: 'create', resource: 'monitoring-aset' } }, { 'Idempotency-Key': kunciIterasi('life') }));
    record(dibuat, writeLatency, 201, 'monitoring dibuat 201');

    if (dibuat.status !== 201) {
        return;
    }

    const id = dibuat.json('data.id');

    if (!String(dibuat.json('data.kode')).startsWith(PREFIX)) {
        violation('wrong_sequence_prefix_on_monitoring');
    }

    if (String(dibuat.json('data.tenant_id')) !== String(tenant.id)) {
        violation('monitoring_written_to_other_tenant');
    }

    const diisi = http.post(`${MON}/${id}/isi-otomatis`, JSON.stringify({ version: dibuat.json('data.version') }), paramsUntuk(tenant, { tags: { op: 'fill', resource: 'monitoring-aset' } }));
    record(diisi, writeLatency, 200, 'isi otomatis 200');

    if (diisi.status !== 200) {
        return;
    }

    // Isi otomatis hanya membawa aset tenant sendiri di lokasi itu, tidak lebih dan tidak kurang.
    if (himpunan(diisi.json('data.details')) !== [...tenant.asetIds].sort().join(',')) {
        violation('fill_brought_wrong_assets', { dapat: (diisi.json('data.details') || []).length });
    }

    const hilang = tenant.asetIds[exec.scenario.iterationInTest % tenant.asetIds.length];
    const disimpan = http.patch(
        `${MON}/${id}`,
        badan(tenant, { details: tenant.asetIds.map((asetId) => ({ aset_id: asetId, ada: asetId !== hilang })), version: diisi.json('data.version') }),
        paramsUntuk(tenant, { tags: { op: 'update', resource: 'monitoring-aset' } }),
    );
    record(disimpan, writeLatency, 200, 'temuan disimpan 200');

    if (disimpan.status !== 200) {
        return;
    }

    const selesai = http.post(`${MON}/${id}/selesaikan`, JSON.stringify({ version: disimpan.json('data.version') }), paramsUntuk(tenant, { tags: { op: 'complete', resource: 'monitoring-aset' } }));
    record(selesai, writeLatency, 200, 'selesai 200');

    if (selesai.status !== 200) {
        return;
    }

    completions.add(1);

    if (selesai.json('data.status') !== 'selesai') {
        violation('monitoring_status_did_not_move');
    }

    for (const baris of selesai.json('data.details') || []) {
        const harap = baris.aset_id === hilang ? 'tidak_sesuai' : 'sesuai';

        if (baris.hasil !== harap || baris.sistem_lifecycle_state !== 'received') {
            violation('monitoring_result_breaks_rule', { hasil: baris.hasil, harap });
        }
    }
}

/** Dokumen yang sudah selesai wajib ditolak 422 untuk ubah, isi otomatis, dan arsip. */
function probeTerkunci(tenant) {
    lockedProbes.add(1);
    // SELFTEST mengubah draf milik sendiri: dijawab 200 dan wajib terbaca sebagai pelanggaran.
    const sasaran = SELFTEST ? tenant.seedId : tenant.lockedId;
    const dibaca = http.get(`${MON}/${sasaran}`, paramsUntuk(tenant, { tags: { op: 'show', resource: 'monitoring-aset' } }));

    if (dibaca.status !== 200) {
        recordFailure(dibaca, 'probe-locked');

        return;
    }

    const params = paramsUntuk(tenant, { tags: { op: 'probe_locked', resource: 'monitoring-aset' }, responseCallback: http.expectedStatuses(200, 409, 422) });
    const diubah = http.patch(`${MON}/${sasaran}`, badan(tenant, { details: [], version: dibaca.json('data.version') }), params);
    writeLatency.add(diubah.timings.duration);
    recordFailure(diubah, 'probe-locked');

    if (diubah.status === 200) {
        violation('completed_monitoring_changed');
    }
}

/**
 * Balapan idempotency: dua permintaan identik berbarengan wajib menghasilkan tepat satu dokumen,
 * dan satu nomor.
 */
function balapanIdempotency(tenant) {
    const kunci = kunciIterasi('idem');
    const params = paramsUntuk(tenant, { tags: { op: 'race', resource: 'monitoring-aset' } }, { 'Idempotency-Key': kunci });
    const paramsKedua = SELFTEST ? paramsUntuk(tenant, { tags: { op: 'race', resource: 'monitoring-aset' } }, { 'Idempotency-Key': `${kunci}-selftest` }) : params;
    const [kiri, kanan] = http.batch([
        ['POST', MON, badan(tenant), params],
        ['POST', MON, badan(tenant), paramsKedua],
    ]);
    [kiri, kanan].forEach((response) => writeLatency.add(response.timings.duration));
    const sukses = (response) => response.status >= 200 && response.status < 300;

    if (!sukses(kiri) || !sukses(kanan)) {
        recordFailure(kiri, 'idem-race');
        recordFailure(kanan, 'idem-race');

        return;
    }

    if (kiri.json('data.id') !== kanan.json('data.id') || kiri.json('data.kode') !== kanan.json('data.kode')) {
        violation('monitoring_idempotency_produced_two_records');
    } else if (kiri.status === 200 || kanan.status === 200) {
        idempotencyReplays.add(1);
    }
}

/** Sesi tenant A tidak boleh membaca atau menulis dokumen tenant B. */
function probeLintasTenant(data, tenant) {
    crossTenantProbes.add(1);
    const korban = data.tenants[(tenant.index + 1) % data.tenants.length];
    const sasaran = SELFTEST ? tenant.seedId : korban.seedId;

    const dibaca = http.get(`${MON}/${sasaran}`, paramsUntuk(tenant, { tags: { op: 'probe_read', resource: 'monitoring-aset' }, responseCallback: http.expectedStatuses(404) }));
    readLatency.add(dibaca.timings.duration);
    recordFailure(dibaca, 'probe-cross-read');

    if (dibaca.status === 200) {
        violation('monitoring_cross_tenant_read');
    }

    const diisi = http.post(`${MON}/${korban.seedId}/isi-otomatis`, JSON.stringify({ version: 1 }), paramsUntuk(tenant, { tags: { op: 'probe_write', resource: 'monitoring-aset' }, responseCallback: http.expectedStatuses(404) }));
    writeLatency.add(diisi.timings.duration);
    recordFailure(diisi, 'probe-cross-write');

    if (diisi.status === 200) {
        violation('monitoring_cross_tenant_fill');
    }
}

/** Hak atas satu master tidak boleh merembet ke monitoring. */
function probeEskalasiHak(sempit) {
    scopeProbes.add(1);
    const ditolak = http.get(MON, paramsUntuk(sempit, { tags: { op: 'probe_scope_denied', resource: 'monitoring-aset' }, responseCallback: http.expectedStatuses(403) }));
    readLatency.add(ditolak.timings.duration);
    recordFailure(ditolak, 'probe-scope');

    if (ditolak.status === 200) {
        violation('monitoring_permission_escalation');
    }
}

/**
 * Balapan pada dokumen yang sama dari versi yang sama.
 *
 * Tiga penulis berbarengan: PATCH himpunan A (dua aset pertama, "ada"), PATCH himpunan B (aset
 * ketiga, "tidak ada"), dan isi otomatis (semua aset). Paling banyak satu yang boleh 200; sisanya
 * 409, atau 422 bila dokumennya sudah selesai. Himpunan baris yang terbaca sesudahnya wajib salah
 * satu dari A, B, semua aset, atau kosong (dokumen belum pernah tersentuh) — gabungan A dan B
 * berarti dua penggantian menumpuk.
 *
 * Sesekali penulis menyamakan baris ke "semua ada" lalu dua penyelesaian dari versi yang sama
 * dikirim berbarengan; paling banyak satu yang boleh 200.
 */
function balapan(tenant) {
    const arena = tenant.raceDocs;
    const docId = arena[Math.floor(Date.now() / 250) % arena.length];
    const dibaca = http.get(`${MON}/${docId}`, paramsUntuk(tenant, { tags: { op: 'show', resource: 'monitoring-aset' } }));
    record(dibaca, readLatency, 200, 'arena dibaca 200');

    if (dibaca.status !== 200 || dibaca.json('data.status') !== 'draft') {
        return;
    }

    const versi = Number(dibaca.json('data.version'));
    const [a0, a1, b0] = tenant.asetIds;
    const setA = [a0, a1].sort().join(',');
    const setB = [b0].sort().join(',');
    const setSemua = [...tenant.asetIds].sort().join(',');
    const params = paramsUntuk(tenant, { tags: { op: 'race_write', resource: 'monitoring-aset' }, responseCallback: http.expectedStatuses(200, 409, 422) });

    if (Math.random() < 0.05) {
        const disamakan = http.patch(`${MON}/${docId}`, badan(tenant, { details: tenant.asetIds.map((asetId) => ({ aset_id: asetId, ada: true })), version: versi }), params);
        recordFailure(disamakan, 'race-align');

        if (disamakan.status !== 200) {
            return;
        }

        const versiBaru = Number(disamakan.json('data.version'));
        const hasil = http.batch([
            ['POST', `${MON}/${docId}/selesaikan`, JSON.stringify({ version: versiBaru }), params],
            ['POST', `${MON}/${docId}/selesaikan`, JSON.stringify({ version: versiBaru }), params],
        ]);
        hasil.forEach((response) => {
            writeLatency.add(response.timings.duration);
            recordFailure(response, 'race-complete');
        });

        if (hasil.some(tidakTersedia)) {
            return;
        }

        races.add(1, { kind: 'complete' });
        const menang = hasil.filter((response) => response.status === 200).length;

        if (menang > 1) {
            doubleWins.add(1, { kind: 'complete' });
        }

        if (menang === 1) {
            completions.add(1);
        }

        return;
    }

    const hasil = http.batch([
        ['PATCH', `${MON}/${docId}`, badan(tenant, { details: [{ aset_id: a0, ada: true }, { aset_id: a1, ada: true }], version: versi }), params],
        ['PATCH', `${MON}/${docId}`, badan(tenant, { details: [{ aset_id: b0, ada: false }], version: versi }), params],
        ['POST', `${MON}/${docId}/isi-otomatis`, JSON.stringify({ version: versi }), params],
    ]);
    hasil.forEach((response) => {
        writeLatency.add(response.timings.duration);
        recordFailure(response, 'race-write');
    });

    if (hasil.some(tidakTersedia)) {
        return;
    }

    races.add(1, { kind: 'write' });
    const menang = hasil.filter((response) => response.status === 200).length;

    if (menang > 1) {
        doubleWins.add(1, { kind: 'write' });
        console.error(`monitoring_double_wins: ${hasil.map((response) => response.status).join('/')}`);
    }

    const ulang = http.get(`${MON}/${docId}`, paramsUntuk(tenant, { tags: { op: 'show', resource: 'monitoring-aset' } }));
    record(ulang, readLatency, 200, 'arena dibaca ulang 200');

    if (ulang.status !== 200) {
        return;
    }

    const terbaca = himpunan(ulang.json('data.details'));
    // SELFTEST menguji pendeteksi gabungan dengan menganggap himpunan yang sah justru gabungan A dan B.
    const sah = SELFTEST ? [[a0, a1, b0].sort().join(',')] : [setA, setB, setSemua, ''];

    if (!sah.includes(terbaca)) {
        blendedSets.add(1);
        console.error(`monitoring_blended_line_sets: ${terbaca}`);
    }

    // Himpunan A selalu "ada", himpunan B selalu "tidak ada": nilai campuran berarti baris dua
    // penulis bertumpuk walau himpunannya kebetulan sama.
    const baris = ulang.json('data.details') || [];

    if (terbaca === setA && baris.some((row) => row.ada !== true)) {
        blendedSets.add(1, { kind: 'values' });
    }

    if (terbaca === setB && baris.some((row) => row.ada !== false)) {
        blendedSets.add(1, { kind: 'values' });
    }
}

// ---------------------------------------------------------------- vu loop

let sempitCache = null;

function tenantSempit(sempit) {
    if (sempitCache === null) {
        sempitCache = bangunJar(sempit);
    }

    return sempitCache;
}

export default function (data) {
    const tenant = tenantVu(data.tenants, exec.vu.idInTest);

    if (PROFILE === 'race') {
        balapan(tenant);

        return;
    }

    const roll = Math.random();

    if (roll < 0.35) {
        siklus(tenant);
    } else if (roll < 0.5) {
        const daftar = http.get(MON, paramsUntuk(tenant, { tags: { op: 'list', resource: 'monitoring-aset' } }));
        record(daftar, readLatency, 200, 'daftar 200');

        if (daftar.status === 200) {
            const asing = (daftar.json('data') || []).filter((row) => String(row.tenant_id) !== String(tenant.id));

            if (asing.length > 0) {
                violations.add(asing.length, { kind: 'monitoring_list_leaks_other_tenant' });
            }
        }
    } else if (roll < 0.6) {
        const dibaca = http.get(`${MON}/${tenant.seedId}`, paramsUntuk(tenant, { tags: { op: 'show', resource: 'monitoring-aset' } }));
        record(dibaca, readLatency, 200, 'show seed 200');
    } else if (roll < 0.67) {
        const laporan = http.get(ASET('laporan/laporan-monitoring-aset?dari=2026-08-01'), paramsUntuk(tenant, { tags: { op: 'report', resource: 'laporan-monitoring-aset' } }));
        record(laporan, readLatency, 200, 'pratinjau laporan 200');
    } else if (roll < 0.77) {
        balapanIdempotency(tenant);
    } else if (roll < 0.85) {
        probeTerkunci(tenant);
    } else if (roll < 0.95) {
        probeLintasTenant(data, tenant);
    } else {
        probeEskalasiHak(SELFTEST ? tenant : tenantSempit(data.sempit));
    }
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];

        return value === undefined ? null : Number(value.toFixed(2));
    };
    const count = (name) => data.metrics[name]?.values?.count ?? 0;

    const summary = {
        skenario: 'monitoring',
        profile: PROFILE,
        run_id: RUN_ID,
        fixture: FIXTURE,
        selftest: SELFTEST,
        vus_configured: PROFILE === 'latency' ? Number(__ENV.LATENCY_VUS || 16) : VUS,
        tenants: TENANT_COUNT,
        race_docs_per_tenant: PROFILE === 'race' ? RACE_DOCS : 0,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: count('iterations'),
        requests: count('http_reqs'),
        throughput_rps: metric('http_reqs', 'rate'),
        kebenaran: {
            correctness_violations: count('correctness_violations'),
            monitoring_double_wins: count('monitoring_double_wins'),
            monitoring_blended_line_sets: count('monitoring_blended_line_sets'),
            server_errors: count('server_errors'),
            monitoring_races: count('monitoring_races'),
            monitoring_completions: count('monitoring_completions'),
            locked_document_probes: count('locked_document_probes'),
            cross_tenant_probes: count('cross_tenant_probes'),
            permission_scope_probes: count('permission_scope_probes'),
            idempotency_replays: count('idempotency_replays'),
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
        stdout: `\n===== RINGKASAN LOAD TEST monitoring (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-monitoring-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
