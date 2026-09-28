// Load test penerimaan aset dan jurnalnya (feed posting finance, area 9, 10, dan 12), pada runtime
// Core yang sungguhan.
//
// Menyelesaikan penerimaan melakukan tiga hal di satu transaksi: memindahkan status dokumen,
// melahirkan aset bernomor, dan menerbitkan posting ke feed Core — `asset.acquisition` untuk
// pembelian, `asset.opening_balance` untuk saldo awal aset lama. Yang dijaga berkas ini:
//
//   PROFILE=race        Yang paling penting. Seluruh VU satu arena menyelesaikan draf yang SAMA
//                       pada detik yang sama. Tepat satu yang boleh menang; yang lain ditolak
//                       (409 versi usang atau 422 sudah selesai), dan dokumen itu berakhir dengan
//                       tepat satu set aset dan tepat satu posting. Penyelesaian kedua yang lolos
//                       berarti aset kembar dan hutang — atau saldo awal — dua kali di aplikasi
//                       finance. Separuh draf arena adalah saldo awal.
//
//   PROFILE=adjust-race Seluruh VU satu arena mengoreksi nilai perolehan aset yang SAMA pada detik
//                       yang sama (area 12). Semuanya boleh berhasil, tetapi berurutan: tiap koreksi
//                       menerbitkan `asset.acquisition_adjustment` bernomor urut berikutnya, dan
//                       selisihnya dihitung dari nilai yang sudah dikoreksi pendahulunya. Koreksi yang
//                       membaca nilai lama berarti buku besar dan register berpisah jalan tanpa ada
//                       yang ditolak — hanya `verify.sql` yang dapat melihatnya. Separuh aset arena
//                       berasal dari saldo awal.
//
//   PROFILE=saturation  Beban serentak membuat dan menyelesaikan penerimaan di banyak tenant, satu
//                       dari tiga di antaranya saldo awal, sambil sesekali mengimpor saldo awal
//                       dari CSV dan mengoreksi nilai perolehan aset yang baru lahir. Yang digate: KEBENARAN — tidak ada 5xx aplikasi, setiap penerimaan
//                       selesai membawa posting berjenis yang benar, dan tidak ada dokumen tenant
//                       lain yang terbaca atau terselesaikan.
//
// Oracle SQL-nya di `verify.sql`: satu posting per penerimaan selesai, debit posting perolehan sama
// dengan nilai perolehan asal ditambah PPN, akumulasi jurnal saldo awal sama dengan akumulasi awal
// register, jumlah aset sama dengan jumlah unit barisnya, dan rantai koreksi tiap aset tersambung
// dari nilai jurnal perolehannya sampai nilai register hari ini.

import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { siapkanTenant, paramsUntuk, bangunJar, tenantVu, urlModule, BASE, FIXTURE, RUN_ID } from '../lib.js';

const PROFILE = __ENV.PROFILE || 'race';
const VUS = Number(__ENV.VUS || 32);
const DURATION = __ENV.DURATION || '90s';
// Balapan dipusatkan pada sedikit tenant: menyebar beban membuat dua penyelesaian hampir tidak
// pernah jatuh pada dokumen yang sama, dan run kembali hijau tanpa membuktikan apa pun.
const RACE_TENANTS = Number(__ENV.RACE_TENANTS || 4);
const TENANT_COUNT = PROFILE === 'saturation' ? Number(__ENV.TENANTS || 128) : RACE_TENANTS;
// Satu draf per detik run; lebih dari durasi supaya indeksnya tidak berputar ke draf yang sudah selesai.
const RACE_DRAFTS = Number(__ENV.RACE_DRAFTS || 150);
// Penerimaan selesai per arena untuk profil adjust-race; tiap penerimaan melahirkan UNITS aset.
const ADJUST_RECEIPTS = Number(__ENV.ADJUST_RECEIPTS || 6);
const UNITS = 2;
const ALASAN_KOREKSI = 'Koreksi faktur uji beban';

const ASET = (path) => urlModule('management-aset', path);

/*
 * Mode pembuktian oracle. Yang dirusak adalah permintaan, bukan produknya: probe lintas tenant
 * diarahkan ke dokumen milik sendiri, sehingga penyelesaian dan pratinjau yang seharusnya 404
 * dijawab 200 — `correctness_violations` wajib naik. Kalau tetap nol pada run seperti ini,
 * pembandingnya mati.
 */
const SELFTEST = __ENV.SELFTEST === '1';

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
const timeouts = new Counter('client_timeouts');
const raceWins = new Counter('race_wins');
const raceLosses = new Counter('race_losses');
const receiptsCompleted = new Counter('receipts_completed');
const openingBalancesCompleted = new Counter('opening_balances_completed');
const importsApplied = new Counter('imports_applied');
const adjustmentsPublished = new Counter('adjustments_published');
// Koreksi yang sah tidak pernah ditolak: penghalangnya (periode penyusutan, alasan, tanggal, presisi)
// tidak ada di run ini. Penolakan berarti balapan yang salah dibaca sebagai konflik.
const adjustRejected = new Counter('adjust_rejected');
// Penerbitan nomor Core yang gagal (deadlock 40P01 pada blok nomor), yang modul jawab 422. Bukan
// cacat area 9 atau 10 dan bukan gate di sini; dihitung supaya tidak tenggelam di antara 422 yang sah.
const numberSequenceFailures = new Counter('number_sequence_failures');

const correctnessThresholds = {
    correctness_violations: ['count==0'],
    server_errors: ['count==0'],
};

const umum = {
    thresholds: correctnessThresholds,
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    setupTimeout: '20m',
    // Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per host.
    batch: 32,
    batchPerHost: 16,
};

// Run yang tidak menyelesaikan satu dokumen pun — atau balapan tanpa pihak yang kalah, atau tanpa
// satu pun saldo awal — lulus semua gate kebenaran tanpa menguji apa-apa, jadi semuanya ikut digate.
export const options =
    PROFILE === 'saturation'
        ? {
              ...umum,
              thresholds: {
                  ...correctnessThresholds,
                  receipts_completed: ['count>0'],
                  opening_balances_completed: ['count>0'],
                  imports_applied: ['count>0'],
                  adjustments_published: ['count>0'],
                  adjust_rejected: ['count==0'],
              },
              scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '60s' } },
          }
        : PROFILE === 'adjust-race'
          ? {
                ...umum,
                thresholds: { ...correctnessThresholds, adjustments_published: ['count>0'], adjust_rejected: ['count==0'] },
                scenarios: { adjust_race: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '30s' } },
            }
          : {
              ...umum,
              thresholds: { ...correctnessThresholds, race_wins: ['count>0'], race_losses: ['count>0'], opening_balances_completed: ['count>0'] },
              scenarios: { race: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '30s' } },
          };

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
    } else if (response.status === 422 && String(response.body).includes('"number_sequence_failed"')) {
        numberSequenceFailures.add(1, { op });
    }

    return response;
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

// ---------------------------------------------------------------- setup

/**
 * Draf pembelian, atau draf saldo awal: aset lama bertanggal sebelum cutover (1 Januari 2026) tanpa
 * vendor dan PPN, dengan akumulasi dan periode berjalan buku yang di-post (area 10).
 */
function badanDraf(tenant, saldoAwal = false) {
    const baris = {
        nama: saldoAwal ? 'Kursi tunggu lama uji beban' : 'Kursi tunggu uji beban',
        group_aset_id: tenant.groupId,
        jenis_aset_id: tenant.jenisId,
        jumlah: UNITS,
        // Harga satuan bertiga desimal: nilai barisnya dibulatkan sekali dan dibagi ke aset.
        nilai_per_unit: '333333.333',
        ppn_per_unit: saldoAwal ? '0' : '36666.667',
    };

    if (saldoAwal) {
        return {
            legal_entity_id: tenant.legalEntityId,
            responsible_org_unit_id: tenant.orgUnitId,
            cara_perolehan: 'saldo_awal',
            tanggal: '2025-06-01',
            currency_code: 'IDR',
            details: [{ ...baris, akumulasi_per_unit: '100000.00', periode_berjalan: 12 }],
        };
    }

    return {
        legal_entity_id: tenant.legalEntityId,
        responsible_org_unit_id: tenant.orgUnitId,
        tanggal: '2026-09-28',
        currency_code: 'IDR',
        vendor_id: tenant.vendorId,
        vendor_invoice_reference: `INV-${RUN_ID}`,
        details: [baris],
    };
}

function buatDraf(tenant, kunci, saldoAwal) {
    return http.post(ASET('penerimaan-aset'), JSON.stringify(badanDraf(tenant, saldoAwal)), paramsUntuk(tenant, { tags: { op: 'create', resource: 'penerimaan-aset' } }, { 'Idempotency-Key': kunci }));
}

export function setup() {
    const tenants = siapkanTenant(TENANT_COUNT, ['management-aset']);
    const jars = tenants.map((tenant) => bangunJar(tenant));

    // 1. Vendor milik Core per entitas legal (K-06): pembelian pada mode bawaan wajib membawanya.
    const vendors = wajibBatch(
        'vendor',
        jars.map((tenant) => ['POST', `${BASE}/api/v1/vendors`, JSON.stringify({ legal_entity_id: tenant.legalEntityId, party_name: `Pemasok uji beban ${RUN_ID}` }), paramsUntuk(tenant)]),
        [201],
    );

    // 2. Pengiriman posting diaktifkan dengan cutover, supaya penerbit menilai pemetaan sungguhan dan
    //    saldo awal punya tanggal jurnal.
    wajibBatch(
        'setelan feed',
        jars.map((tenant) => ['PUT', `${BASE}/api/v1/organizations/${tenant.legalEntityId}/finance-posting`, JSON.stringify({ enabled: true, cutover_date: '2026-01-01' }), paramsUntuk(tenant)]),
        [200],
    );

    // 3. Group, jenis, dan buku yang di-post, dibuat sendiri. Tenant baru tidak membawa data awal,
    //    dan penerimaan bergroup tanpa buku yang di-post ditolak diselesaikan (K-26): tanpa matriks
    //    group x buku ini setiap penyelesaian dijawab 422 dan run hijau tanpa satu pun jurnal.
    //    Kuncinya diikat ke FIXTURE, jadi run berikutnya pada fixture yang sama memakai ulang ketiganya.
    const kode = (awalan) => `${awalan}-${FIXTURE}`.toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 20);
    const jenis = wajibBatch(
        'jenis aset',
        jars.map((tenant, index) => ['POST', ASET('jenis-aset'), JSON.stringify({ nama: `Jenis penerimaan ${FIXTURE}` }), paramsUntuk(tenant, {}, { 'Idempotency-Key': `rcp-${FIXTURE}-jenis-${index}` })]),
        [200, 201],
    );
    const groups = wajibBatch(
        'group aset',
        jars.map((tenant, index) => ['POST', ASET('group-aset'), JSON.stringify({ kode: kode('RCP'), nama: `Group penerimaan ${FIXTURE}` }), paramsUntuk(tenant, {}, { 'Idempotency-Key': `rcp-${FIXTURE}-group-${index}` })]),
        [200, 201],
    );
    const buku = wajibBatch(
        'buku yang di-post',
        jars.map((tenant, index) => [
            'POST',
            ASET('buku-penyusutan'),
            JSON.stringify({ kode: kode('RCP-BUKU'), nama: `Buku komersial ${FIXTURE}`, posting_layer: 'current' }),
            paramsUntuk(tenant, {}, { 'Idempotency-Key': `rcp-${FIXTURE}-buku-${index}` }),
        ]),
        [200, 201],
    );
    // Matriks menuntut profil utama walau penyusutannya dimatikan; masa manfaatnya (48 periode)
    // juga batas periode berjalan saldo awal.
    const profil = wajibBatch(
        'profil penyusutan',
        jars.map((tenant, index) => [
            'POST',
            ASET('profil-penyusutan'),
            JSON.stringify({ nama: `Profil penerimaan ${FIXTURE}`, method: 'straight_line', frequency: 'monthly', year_basis: 'calendar', useful_life_periods: 48, convention: 'full_month' }),
            paramsUntuk(tenant, {}, { 'Idempotency-Key': `rcp-${FIXTURE}-profil-${index}` }),
        ]),
        [200, 201],
    );
    wajibBatch(
        'matriks group x buku',
        jars.map((tenant, index) => [
            'PUT',
            `${ASET('group-aset')}/${groups[index].json('data.id')}/buku-penyusutan`,
            JSON.stringify({ rows: [{ buku_id: buku[index].json('data.id'), depreciation_profile_id: profil[index].json('data.id'), depreciate: false }] }),
            paramsUntuk(tenant),
        ]),
        [200],
    );

    const siap = tenants.map((tenant, index) => ({
        ...tenant,
        vendorId: String(vendors[index].json('data.id')),
        groupId: String(groups[index].json('data.id')),
        groupKode: String(groups[index].json('data.kode')),
        jenisId: String(jenis[index].json('data.id')),
        jenisKode: String(jenis[index].json('data.kode')),
    }));

    // 4. Satu draf per tenant untuk probe lintas tenant.
    const probe = wajibBatch(
        'draf probe',
        siap.map((tenant) => {
            const jar = bangunJar(tenant);

            return ['POST', ASET('penerimaan-aset'), JSON.stringify(badanDraf(tenant)), paramsUntuk(jar, {}, { 'Idempotency-Key': `rcp-probe-${RUN_ID}-${tenant.index}` })];
        }),
        [200, 201],
    );
    siap.forEach((tenant, index) => {
        tenant.probeReceiptId = String(probe[index].json('data.id'));
    });

    // Pratinjau draf probe memakai aturan yang sama dengan penyelesaian. Penghalang di sini berarti
    // setiap penyelesaian dalam run akan dijawab 422, jadi setup berhenti alih-alih hijau palsu.
    const pratinjau = wajibBatch(
        'pratinjau draf probe',
        siap.map((tenant) => ['GET', `${ASET('penerimaan-aset')}/${tenant.probeReceiptId}/pratinjau-posting`, null, paramsUntuk(bangunJar(tenant))]),
        [200],
    );
    pratinjau.forEach((response, index) => {
        if ((response.json('data.blockers') || []).length > 0) {
            fail(`setup tenant ${index}: draf probe berpenghalang ${JSON.stringify(response.json('data.blockers'))}`);
        }
    });
    console.log(`setup: jurnal draf probe ${pratinjau[0].json('data.status')}, masalah ${JSON.stringify((pratinjau[0].json('data.problems') || []).map((masalah) => masalah.code))}`);

    // 5. Profil race: draf yang akan diperebutkan, satu per detik run per arena, bergantian pembelian
    //    dan saldo awal. Dibuat berurutan: pembuatan serentak dalam satu tenant menabrak deadlock
    //    penerbitan nomor Core (`number_sequence_allocations`, 40P01) yang dijawab 422 — cacat Core
    //    yang sudah ada sebelum area 9 dan diukur terpisah lewat `number_sequence_failures`.
    const arenas = [];

    if (PROFILE === 'race') {
        for (const tenant of siap) {
            const jar = bangunJar(tenant);
            const drafts = [];

            for (let urut = 0; urut < RACE_DRAFTS; urut++) {
                const saldoAwal = urut % 2 === 1;
                const draf = http.post(ASET('penerimaan-aset'), JSON.stringify(badanDraf(tenant, saldoAwal)), paramsUntuk(jar, {}, { 'Idempotency-Key': `rcp-race-${RUN_ID}-${tenant.index}-${urut}` }));

                if (draf.status !== 200 && draf.status !== 201) {
                    fail(`setup draf arena ${tenant.index} nomor ${urut}: ${draf.status} ${String(draf.body).slice(0, 400)}`);
                }

                drafts.push({ id: String(draf.json('data.id')), saldoAwal });
            }

            arenas.push({ tenantIndex: tenant.index, drafts });
        }
    }

    // 6. Profil adjust-race: penerimaan yang diselesaikan lebih dulu, bergantian pembelian dan saldo
    //    awal, supaya aset arena punya jurnal perolehan yang dikoreksi. Berurutan, alasannya sama
    //    dengan draf arena di atas.
    if (PROFILE === 'adjust-race') {
        for (const tenant of siap) {
            const jar = bangunJar(tenant);
            const assets = [];

            for (let urut = 0; urut < ADJUST_RECEIPTS; urut++) {
                const saldoAwal = urut % 2 === 1;
                const draf = http.post(ASET('penerimaan-aset'), JSON.stringify(badanDraf(tenant, saldoAwal)), paramsUntuk(jar, {}, { 'Idempotency-Key': `rcp-adj-${RUN_ID}-${tenant.index}-${urut}` }));

                if (draf.status !== 200 && draf.status !== 201) {
                    fail(`setup draf koreksi ${tenant.index} nomor ${urut}: ${draf.status} ${String(draf.body).slice(0, 400)}`);
                }

                const id = String(draf.json('data.id'));
                const selesai = http.post(`${ASET('penerimaan-aset')}/${id}/selesaikan`, JSON.stringify({ version: 1 }), paramsUntuk(jar));

                if (selesai.status !== 200) {
                    fail(`setup penyelesaian koreksi ${tenant.index} nomor ${urut}: ${selesai.status} ${String(selesai.body).slice(0, 400)}`);
                }

                const daftar = http.get(`${ASET('penerimaan-aset')}/${id}/aset`, paramsUntuk(jar));
                (daftar.json('data') || []).forEach((aset) => assets.push(String(aset.id)));
            }

            if (assets.length !== ADJUST_RECEIPTS * UNITS) {
                fail(`setup arena koreksi ${tenant.index}: ${assets.length} aset, bukan ${ADJUST_RECEIPTS * UNITS}`);
            }

            arenas.push({ tenantIndex: tenant.index, assets });
        }
    }

    console.log(`setup: ${siap.length} tenant, ${arenas.length} arena`);

    return { tenants: siap, arenas };
}

// ---------------------------------------------------------------- workload

function selesaikan(tenant, id, op) {
    return record(
        http.post(`${ASET('penerimaan-aset')}/${id}/selesaikan`, JSON.stringify({ version: 1 }), paramsUntuk(tenant, {
            tags: { op, resource: 'penerimaan-aset' },
            responseCallback: http.expectedStatuses(200, 409, 422),
        })),
        writeLatency,
        op,
    );
}

/**
 * Dokumen selesai wajib membawa posting berjenis yang benar, dan tepat `UNITS` aset. Memulangkan id
 * aset yang lahir, untuk dikoreksi pemanggil.
 */
function periksaSelesai(tenant, id, saldoAwal) {
    const dokumen = record(http.get(`${ASET('penerimaan-aset')}/${id}`, paramsUntuk(tenant, { tags: { op: 'read', resource: 'penerimaan-aset' } })), readLatency, 'read');

    if (dokumen.status !== 200) {
        return [];
    }

    if (dokumen.json('data.status') !== 'selesai') {
        return [];
    }

    const status = dokumen.json('data.posting.status');

    if (!['pending', 'held', 'manual'].includes(status)) {
        violations.add(1, { kind: 'completed_without_posting' });
        console.error(`correctness violation: completed_without_posting ${id} (${status})`);
    }

    // Saldo awal terbit sebagai `asset.opening_balance` (`AST-OPB-`), pembelian sebagai
    // `asset.acquisition` (`AST-ACQ-`); jenis yang tertukar berarti jurnal yang salah di buku besar.
    const awalan = saldoAwal ? 'AST-OPB-' : 'AST-ACQ-';

    if (!String(dokumen.json('data.posting.posting_id')).startsWith(awalan)) {
        violations.add(1, { kind: 'posting_type_mismatch' });
        console.error(`correctness violation: posting_type_mismatch ${id} (${dokumen.json('data.posting.posting_id')})`);
    }

    const aset = record(http.get(`${ASET('penerimaan-aset')}/${id}/aset`, paramsUntuk(tenant, { tags: { op: 'read', resource: 'penerimaan-aset' } })), readLatency, 'read');

    if (aset.status !== 200) {
        return [];
    }

    const lahir = (aset.json('data') || []).map((baris) => String(baris.id));

    if (lahir.length !== UNITS) {
        violations.add(1, { kind: 'asset_count_mismatch' });
        console.error(`correctness violation: asset_count_mismatch ${id} (${lahir.length})`);
    }

    return lahir;
}

/** Tanggal hari ini menurut jam k6 (UTC), seperti layar yang mengirim tanggal lokal penggunanya. */
function hariIni() {
    return new Date().toISOString().slice(0, 10);
}

/**
 * Mengoreksi nilai perolehan satu aset. Koreksi yang sah wajib diterima, membawa nilai yang dikirim,
 * dan — kecuali nilainya kebetulan sama dengan nilai sekarang — menerbitkan `AST-ADJ-<id aset>-<n>`.
 */
function koreksi(tenant, asetId, nilai, op) {
    const jawab = record(
        http.patch(
            `${ASET('aset')}/${asetId}`,
            JSON.stringify({ acquisition_value: nilai, reason: ALASAN_KOREKSI, adjustment_date: hariIni() }),
            paramsUntuk(tenant, { tags: { op, resource: 'aset' }, responseCallback: http.expectedStatuses(200) }),
        ),
        writeLatency,
        op,
    );
    check(jawab, { 'koreksi nilai diterima': (response) => response.status === 200 });

    if (jawab.status !== 200) {
        if (jawab.status !== 0 && jawab.status < 500) {
            adjustRejected.add(1);
            console.error(`koreksi ditolak ${asetId}: ${jawab.status} ${String(jawab.body).slice(0, 300)}`);
        }

        return;
    }

    if (Number(jawab.json('data.acquisition_value')) !== Number(nilai)) {
        violations.add(1, { kind: 'adjustment_value_lost' });
        console.error(`correctness violation: adjustment_value_lost ${asetId} (${jawab.json('data.acquisition_value')} != ${nilai})`);
    }

    const postingId = jawab.json('data.adjustment.posting.posting_id');

    if (postingId === null || postingId === undefined) {
        return;
    }

    if (!String(postingId).startsWith(`AST-ADJ-${asetId}-`) || !['pending', 'held'].includes(jawab.json('data.adjustment.posting.status'))) {
        violations.add(1, { kind: 'adjustment_posting_mismatch' });
        console.error(`correctness violation: adjustment_posting_mismatch ${asetId} (${postingId} ${jawab.json('data.adjustment.posting.status')})`);

        return;
    }

    adjustmentsPublished.add(1);
}

/** Nilai perolehan baru yang berbeda per VU dan iterasi, dua desimal. */
function nilaiKoreksi() {
    const acak = (exec.vu.idInTest * 7919 + exec.vu.iterationInInstance * 104729) % 200000;

    return (250000 + acak + (exec.vu.idInTest % 100) / 100).toFixed(2);
}

/** Dokumen tenant lain tidak boleh terbaca pratinjaunya maupun terselesaikan. */
function probe(tenant, data) {
    const lain = data.tenants[(tenant.index + 1) % data.tenants.length];

    if (!lain || lain.index === tenant.index) {
        return;
    }

    const sasaran = SELFTEST ? tenant.probeReceiptId : lain.probeReceiptId;
    const baca = record(
        http.get(`${ASET('penerimaan-aset')}/${sasaran}/pratinjau-posting`, paramsUntuk(tenant, { tags: { op: 'probe', resource: 'penerimaan-aset' }, responseCallback: http.expectedStatuses(404) })),
        readLatency,
        'probe',
    );

    if (baca.status === 200) {
        violations.add(1, { kind: 'cross_tenant_preview' });
        console.error('correctness violation: cross_tenant_preview');
    }

    const tulis = record(
        http.post(`${ASET('penerimaan-aset')}/${sasaran}/selesaikan`, JSON.stringify({ version: 999 }), paramsUntuk(tenant, { tags: { op: 'probe', resource: 'penerimaan-aset' }, responseCallback: http.expectedStatuses(404) })),
        writeLatency,
        'probe',
    );

    // Versi 999 tidak pernah menang, jadi dokumen sendiri pada SELFTEST menjawab 409, bukan 200.
    if (tulis.status === 200 || tulis.status === 409) {
        violations.add(1, { kind: 'cross_tenant_complete' });
        console.error('correctness violation: cross_tenant_complete');
    }
}

/**
 * Impor saldo awal dua baris bertanggal berbeda lewat CSV (TODO 10.6): dua draf wajib lahir, dengan
 * nama dan kunci yang unik per iterasi supaya run tidak memakai ulang hasil iterasi lain.
 */
function imporSaldoAwal(tenant) {
    const kunci = `rcp-imp-${RUN_ID}-${exec.vu.idInTest}-${exec.vu.iterationInInstance}`;
    const csv = [
        'tanggal,nama,group,jenis,jumlah,nilai_per_unit,akumulasi_per_unit,periode_berjalan',
        `2024-03-01,Lemari arsip ${kunci},${tenant.groupKode},${tenant.jenisKode},1,2500000,500000,20`,
        `15/07/2023,Meja kerja ${kunci},${tenant.groupKode},${tenant.jenisKode},2,1500000,375000,12`,
    ].join('\n');
    const jawab = record(
        http.post(
            `${ASET('penerimaan-aset')}/impor-saldo-awal`,
            { file: http.file(csv, 'saldo-awal.csv', 'text/csv'), legal_entity_id: tenant.legalEntityId, responsible_org_unit_id: tenant.orgUnitId, apply: '1' },
            {
                jar: tenant.jar,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': tenant.csrf, 'Idempotency-Key': kunci },
                tags: { op: 'import', resource: 'penerimaan-aset' },
                responseCallback: http.expectedStatuses(200, 201, 422),
            },
        ),
        writeLatency,
        'import',
    );
    check(jawab, { 'impor saldo awal diterapkan': (response) => response.status === 201 });

    if (jawab.status !== 201) {
        return;
    }

    importsApplied.add(1);

    if ((jawab.json('data.receipts') || []).length !== 2) {
        violations.add(1, { kind: 'import_receipt_count' });
        console.error(`correctness violation: import_receipt_count ${String(jawab.body).slice(0, 300)}`);
    }
}

function race(data) {
    const arena = data.arenas[exec.vu.idInTest % data.arenas.length];
    const tenant = tenantVu(data.tenants, arena.tenantIndex);
    const draf = arena.drafts[Math.floor(Date.now() / 1000) % arena.drafts.length];

    const jawab = selesaikan(tenant, draf.id, 'complete_race');
    check(jawab, { 'selesaikan dijawab 200, 409, atau 422': (response) => [200, 409, 422].includes(response.status) });

    if (jawab.status === 200) {
        raceWins.add(1);
        receiptsCompleted.add(1);

        if (draf.saldoAwal) {
            openingBalancesCompleted.add(1);
        }
    } else if (jawab.status === 409 || jawab.status === 422) {
        raceLosses.add(1);
    }

    periksaSelesai(tenant, draf.id, draf.saldoAwal);

    if (exec.vu.iterationInInstance % 5 === 0) {
        probe(tenant, data);
    }
}

/**
 * Koreksi dan pratinjau koreksi atas aset tenant lain wajib 404. SELFTEST mengarahkannya ke aset
 * arena sendiri, sehingga jawaban 200 wajib menaikkan `correctness_violations`.
 */
function probeKoreksi(tenant, data) {
    const lain = data.arenas.find((arena) => arena.tenantIndex !== tenant.index);

    if (!lain) {
        return;
    }

    const milikSendiri = data.arenas.find((arena) => arena.tenantIndex === tenant.index);
    const sasaran = SELFTEST ? milikSendiri.assets[0] : lain.assets[0];
    const pratinjau = record(
        http.get(
            `${ASET('aset')}/${sasaran}/pratinjau-koreksi?acquisition_value=1000.00&adjustment_date=${hariIni()}`,
            paramsUntuk(tenant, { tags: { op: 'probe', resource: 'aset' }, responseCallback: http.expectedStatuses(404) }),
        ),
        readLatency,
        'probe',
    );

    if (pratinjau.status === 200) {
        violations.add(1, { kind: 'cross_tenant_adjustment_preview' });
        console.error('correctness violation: cross_tenant_adjustment_preview');
    }

    const tulis = record(
        http.patch(
            `${ASET('aset')}/${sasaran}`,
            JSON.stringify({ acquisition_value: '1000.00', reason: ALASAN_KOREKSI, adjustment_date: hariIni() }),
            paramsUntuk(tenant, { tags: { op: 'probe', resource: 'aset' }, responseCallback: http.expectedStatuses(404) }),
        ),
        writeLatency,
        'probe',
    );

    if (tulis.status === 200) {
        violations.add(1, { kind: 'cross_tenant_adjustment' });
        console.error('correctness violation: cross_tenant_adjustment');
    }
}

function adjustRace(data) {
    const arena = data.arenas[exec.vu.idInTest % data.arenas.length];
    const tenant = tenantVu(data.tenants, arena.tenantIndex);
    const asetId = arena.assets[Math.floor(Date.now() / 1000) % arena.assets.length];

    koreksi(tenant, asetId, nilaiKoreksi(), 'adjust_race');

    if (exec.vu.iterationInInstance % 5 === 0) {
        probeKoreksi(tenant, data);
    }
}

function saturation(data) {
    const tenant = tenantVu(data.tenants, exec.vu.idInTest);
    const kunci = `rcp-sat-${RUN_ID}-${exec.vu.idInTest}-${exec.vu.iterationInInstance}`;
    const saldoAwal = exec.vu.iterationInInstance % 3 === 2;

    const draf = record(buatDraf(tenant, kunci, saldoAwal), writeLatency, 'create');
    check(draf, { 'draf dibuat': (response) => response.status === 200 || response.status === 201 });

    if (draf.status === 200 || draf.status === 201) {
        const id = String(draf.json('data.id'));
        const jawab = selesaikan(tenant, id, 'complete');
        check(jawab, { 'penerimaan diselesaikan': (response) => response.status === 200 });

        if (jawab.status === 200) {
            receiptsCompleted.add(1);

            if (saldoAwal) {
                openingBalancesCompleted.add(1);
            }

            const lahir = periksaSelesai(tenant, id, saldoAwal);

            // Sebagian aset yang baru lahir langsung dikoreksi (area 12), pembelian maupun saldo awal.
            if (lahir.length > 0 && exec.vu.iterationInInstance % 4 === 1) {
                koreksi(tenant, lahir[0], nilaiKoreksi(), 'adjust');
            }
        }
    }

    if (exec.vu.iterationInInstance % 7 === 3) {
        imporSaldoAwal(tenant);
    }

    probe(tenant, data);
}

export default function (data) {
    if (PROFILE === 'saturation') {
        saturation(data);

        return;
    }

    if (PROFILE === 'adjust-race') {
        adjustRace(data);

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
        skenario: 'receipt-posting',
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
            server_errors: count('server_errors'),
            receipts_completed: count('receipts_completed'),
            opening_balances_completed: count('opening_balances_completed'),
            imports_applied: count('imports_applied'),
            adjustments_published: count('adjustments_published'),
            adjust_rejected: count('adjust_rejected'),
        },
        balapan: {
            race_wins: count('race_wins'),
            race_losses: count('race_losses'),
        },
        kapasitas: {
            number_sequence_failures: count('number_sequence_failures'),
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
        stdout: `\n===== RINGKASAN LOAD TEST receipt-posting (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-receipt-posting-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
