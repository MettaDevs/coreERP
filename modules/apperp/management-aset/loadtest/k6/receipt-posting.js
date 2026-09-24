// Load test penerimaan aset dan jurnal perolehannya (feed posting finance, area 9), pada runtime
// Core yang sungguhan.
//
// Menyelesaikan penerimaan kini melakukan tiga hal di satu transaksi: memindahkan status dokumen,
// melahirkan aset bernomor, dan menerbitkan posting `asset.acquisition` ke feed Core. Yang dijaga
// berkas ini:
//
//   PROFILE=race        Yang paling penting. Seluruh VU satu arena menyelesaikan draf yang SAMA
//                       pada detik yang sama. Tepat satu yang boleh menang; yang lain ditolak
//                       (409 versi usang atau 422 sudah selesai), dan dokumen itu berakhir dengan
//                       tepat satu set aset dan tepat satu posting. Penyelesaian kedua yang lolos
//                       berarti aset kembar dan hutang dua kali di aplikasi finance.
//
//   PROFILE=saturation  Beban serentak membuat dan menyelesaikan penerimaan di banyak tenant.
//                       Yang digate: KEBENARAN — tidak ada 5xx aplikasi, setiap penerimaan selesai
//                       membawa postingnya, dan tidak ada dokumen tenant lain yang terbaca atau
//                       terselesaikan.
//
// Oracle SQL-nya di `verify.sql`: satu posting per penerimaan selesai, jumlah debit posting sama
// dengan nilai register ditambah PPN, dan jumlah aset sama dengan jumlah unit barisnya.

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
const TENANT_COUNT = PROFILE === 'race' ? RACE_TENANTS : Number(__ENV.TENANTS || 128);
// Satu draf per detik run; lebih dari durasi supaya indeksnya tidak berputar ke draf yang sudah selesai.
const RACE_DRAFTS = Number(__ENV.RACE_DRAFTS || 150);
const UNITS = 2;

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
// Penerbitan nomor Core yang gagal (deadlock 40P01 pada blok nomor), yang modul jawab 422. Bukan
// cacat area 9 dan bukan gate di sini; dihitung supaya tidak tenggelam di antara 422 yang sah.
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

// Run yang tidak menyelesaikan satu dokumen pun — atau balapan tanpa pihak yang kalah — lulus semua
// gate kebenaran tanpa menguji apa-apa, jadi keduanya ikut digate.
export const options =
    PROFILE === 'saturation'
        ? {
              ...umum,
              thresholds: { ...correctnessThresholds, receipts_completed: ['count>0'] },
              scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '60s' } },
          }
        : {
              ...umum,
              thresholds: { ...correctnessThresholds, race_wins: ['count>0'], race_losses: ['count>0'] },
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

function badanDraf(tenant) {
    return {
        legal_entity_id: tenant.legalEntityId,
        responsible_org_unit_id: tenant.orgUnitId,
        tanggal: '2026-09-28',
        currency_code: 'IDR',
        vendor_id: tenant.vendorId,
        vendor_invoice_reference: `INV-${RUN_ID}`,
        details: [{
            nama: 'Kursi tunggu uji beban',
            group_aset_id: tenant.groupId,
            jenis_aset_id: tenant.jenisId,
            jumlah: UNITS,
            // Harga satuan bertiga desimal: nilai barisnya dibulatkan sekali dan dibagi ke aset.
            nilai_per_unit: '333333.333',
            ppn_per_unit: '36666.667',
        }],
    };
}

function buatDraf(tenant, kunci) {
    return http.post(ASET('penerimaan-aset'), JSON.stringify(badanDraf(tenant)), paramsUntuk(tenant, { tags: { op: 'create', resource: 'penerimaan-aset' } }, { 'Idempotency-Key': kunci }));
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

    // 2. Pengiriman posting diaktifkan, supaya penerbit menilai pemetaan sungguhan dan tidak
    //    berhenti di "feed mati".
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
    // Matriks menuntut profil utama walau penyusutannya dimatikan; penerimaan tidak memakainya.
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
        jenisId: String(jenis[index].json('data.id')),
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

    // 5. Profil race: draf yang akan diperebutkan, satu per detik run per arena. Dibuat berurutan:
    //    pembuatan serentak dalam satu tenant menabrak deadlock penerbitan nomor Core
    //    (`number_sequence_allocations`, 40P01) yang dijawab 422 — cacat Core yang sudah ada
    //    sebelum area 9 dan diukur terpisah lewat `number_sequence_failures` pada saturasi.
    const arenas = [];

    if (PROFILE === 'race') {
        for (const tenant of siap) {
            const jar = bangunJar(tenant);
            const drafts = [];

            for (let urut = 0; urut < RACE_DRAFTS; urut++) {
                const draf = http.post(ASET('penerimaan-aset'), JSON.stringify(badanDraf(tenant)), paramsUntuk(jar, {}, { 'Idempotency-Key': `rcp-race-${RUN_ID}-${tenant.index}-${urut}` }));

                if (draf.status !== 200 && draf.status !== 201) {
                    fail(`setup draf arena ${tenant.index} nomor ${urut}: ${draf.status} ${String(draf.body).slice(0, 400)}`);
                }

                drafts.push(String(draf.json('data.id')));
            }

            arenas.push({ tenantIndex: tenant.index, drafts });
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

/** Dokumen selesai wajib membawa postingnya, dan tepat `UNITS` aset. */
function periksaSelesai(tenant, id) {
    const dokumen = record(http.get(`${ASET('penerimaan-aset')}/${id}`, paramsUntuk(tenant, { tags: { op: 'read', resource: 'penerimaan-aset' } })), readLatency, 'read');

    if (dokumen.status !== 200) {
        return;
    }

    if (dokumen.json('data.status') !== 'selesai') {
        return;
    }

    const status = dokumen.json('data.posting.status');

    if (!['pending', 'held', 'manual'].includes(status)) {
        violations.add(1, { kind: 'completed_without_posting' });
        console.error(`correctness violation: completed_without_posting ${id} (${status})`);
    }

    const aset = record(http.get(`${ASET('penerimaan-aset')}/${id}/aset`, paramsUntuk(tenant, { tags: { op: 'read', resource: 'penerimaan-aset' } })), readLatency, 'read');

    if (aset.status === 200 && (aset.json('data') || []).length !== UNITS) {
        violations.add(1, { kind: 'asset_count_mismatch' });
        console.error(`correctness violation: asset_count_mismatch ${id} (${(aset.json('data') || []).length})`);
    }
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

function race(data) {
    const arena = data.arenas[exec.vu.idInTest % data.arenas.length];
    const tenant = tenantVu(data.tenants, arena.tenantIndex);
    const id = arena.drafts[Math.floor(Date.now() / 1000) % arena.drafts.length];

    const jawab = selesaikan(tenant, id, 'complete_race');
    check(jawab, { 'selesaikan dijawab 200, 409, atau 422': (response) => [200, 409, 422].includes(response.status) });

    if (jawab.status === 200) {
        raceWins.add(1);
        receiptsCompleted.add(1);
    } else if (jawab.status === 409 || jawab.status === 422) {
        raceLosses.add(1);
    }

    periksaSelesai(tenant, id);

    if (exec.vu.iterationInInstance % 5 === 0) {
        probe(tenant, data);
    }
}

function saturation(data) {
    const tenant = tenantVu(data.tenants, exec.vu.idInTest);
    const kunci = `rcp-sat-${RUN_ID}-${exec.vu.idInTest}-${exec.vu.iterationInInstance}`;

    const draf = record(buatDraf(tenant, kunci), writeLatency, 'create');
    check(draf, { 'draf dibuat': (response) => response.status === 200 || response.status === 201 });

    if (draf.status !== 200 && draf.status !== 201) {
        return;
    }

    const id = String(draf.json('data.id'));
    const jawab = selesaikan(tenant, id, 'complete');
    check(jawab, { 'penerimaan diselesaikan': (response) => response.status === 200 });

    if (jawab.status === 200) {
        receiptsCompleted.add(1);
        periksaSelesai(tenant, id);
    }

    probe(tenant, data);
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
