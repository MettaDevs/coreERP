// Load test penyusutan Fixed Asset Management Aset, pada runtime Core yang sungguhan.
//
// Sampai F7-03 berkas ini berhenti dengan galat di baris `open('./tenants.json')`: ia masih
// memakai berkas fixture berisi token konteks dan header `Authorization: Bearer`, dan keduanya
// tidak ada lagi sejak module masuk ke dalam runtime Core. Sekarang bentuknya sama dengan
// `master-data.js` dan `maintenance.js`: tenant disiapkan lewat alur pendaftaran usaha yang
// sungguhan di dalam `setup()`, identitasnya cookie sesi Core, dan rutenya berawalan
// `/api/modules/management-aset/v1/`.
//
// ## Fixture yang jawabannya sudah diketahui sebelum beban dimulai
//
// Tiap tenant menerima satu aset bernilai 1.000 dengan residu 0, garis lurus sisa umur, masa
// manfaat tiga periode, dan round-off 100 pada matriks group x buku. Angkanya karena itu tidak
// perlu ditebak: 1.000/3 dibulatkan ke bawah ke kelipatan 100 menjadi 300, sisa 700/2 menjadi
// 300, dan periode terakhir mengambil seluruh sisa 400 supaya nilai buku mendarat tepat di
// residu. Saldo akhirnya akumulasi 1.000 dan nilai buku 0.
//
// Setup mengusulkan dan memfinalkan ketiga periode itu, lalu memeriksa saldo akhirnya. Beban
// penuh sesudahnya MENGULANG permintaan yang sama berkali-kali dari banyak VU sekaligus.
// Itulah yang diuji: proposal yang diulang wajib memulangkan periode yang sama dengan nilai
// yang sama, finalisasi yang diulang wajib memulangkan posting yang sama, dan saldo wajib
// tidak bergeser sedikit pun — berapa kali pun ia diminta dan seberapa pun berbarengan.
//
//   PROFILE=saturation     Beban serentak pada seluruh permukaan penyusutan. Yang digate:
//                          KEBENARAN.
//
//   PROFILE=latency        Concurrency tertahan; yang digate p95/p99 per jenis operasi.
//
//   PROFILE=finalize-race  Beberapa VU memfinalkan periode yang SAMA secara bersamaan.
//                          `finalize` mengunci barisnya lalu menambah saldo buku lewat
//                          `incrementEach`; kalau kuncinya tidak menahan, satu periode
//                          menambah saldo dua kali dan tidak ada batasan basis data yang
//                          menolaknya — baris periodenya tetap satu dan tetap sah.
//
// ## Menjalankan SELFTEST: pakai FIXTURE tersendiri
//
// Salah satu pembuktian merah mengirim `reversal`, dan pembalikan memang MENGGESER saldo
// fixture untuk selamanya. Jalankan `SELFTEST=1` dengan `FIXTURE` yang belum pernah dipakai;
// sesudahnya fixture itu tidak lagi dapat dipakai untuk run sungguhan — setup-nya akan berhenti
// dengan galat karena saldo akhirnya bukan 0/1.000 lagi, dan itu memang yang diinginkan.

import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { FIXTURE, RUN_ID, bangunJar, paramsUntuk, sempitkanTenant, siapkanTenant, tenantVu, urlModule } from '../lib.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const DURATION = __ENV.DURATION || '90s';
// Balapan finalisasi sengaja dipusatkan pada sedikit tenant: menyebarnya ke puluhan tenant
// membuat dua permintaan hampir tidak pernah bertemu pada periode yang sama.
const RACE_TENANTS = Number(__ENV.RACE_TENANTS || 4);
const TENANT_COUNT = PROFILE === 'finalize-race' ? RACE_TENANTS : Number(__ENV.TENANTS || 64);

const ASET = (path) => urlModule('management-aset', path);

// Tiga periode bulanan yang deterministik, beserta nilai yang wajib dihasilkannya.
const PERIODE = [
    ['2026-01-01', '2026-01-31'],
    ['2026-02-01', '2026-02-28'],
    ['2026-03-01', '2026-03-31'],
];
const NILAI = [300, 300, 400];
const AKUMULASI_AKHIR = 1000;
const NILAI_BUKU_AKHIR = 0;
// Periode di luar masa manfaat. Dipakai SELFTEST sebagai permintaan yang dirusak; nilainya
// selalu 0 karena nilai buku sudah mendarat di residu, jadi ia tidak menggeser saldo apa pun.
const PERIODE_LUAR = ['2026-04-01', '2026-04-30'];

/*
 * Mode pembuktian oracle; penjelasan panjangnya ada di `master-data.js`.
 *
 * `SELFTEST=1` merusak PERMINTAAN, bukan produknya:
 *
 *   - proposal diminta untuk periode di luar masa manfaat, bukan periode yang dicatat;
 *   - finalisasi diarahkan ke periode itu, sehingga postingnya memang berbeda;
 *   - balapan finalisasi menggerakkan dua periode berbeda, bukan satu periode dua kali;
 *   - sebelum saldo dibaca, sebuah `reversal` dikirim, sehingga saldo memang bergeser;
 *   - probe lintas tenant diarahkan ke periode milik sendiri;
 *   - probe eskalasi hak memakai sesi yang memang berhak penuh.
 *
 * Keenamnya lalu WAJIB menaikkan `correctness_violations` dan membuat run merah. Yang
 * dibuktikan karena itu adalah pendeteksinya hidup — bukan bahwa produknya cacat.
 */
const SELFTEST = __ENV.SELFTEST === '1';

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
// Status 0 berarti klien menyerah sebelum server menjawab: itu batas kapasitas, bukan jawaban
// salah. Dihitung terpisah agar tidak tertukar dengan kesalahan kebenaran.
const timeouts = new Counter('client_timeouts');
const proposalReplays = new Counter('proposal_replays');
const finalizeReplays = new Counter('finalize_replays');
const balanceReads = new Counter('balance_reads');
const finalizeRaces = new Counter('finalize_races');
const crossTenantProbes = new Counter('cross_tenant_probes');
const scopeProbes = new Counter('permission_scope_probes');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

/*
 * Gate kebenaran, dan hanya gate kebenaran. `http_req_failed` dan `checks` sengaja tidak ada di
 * sini; alasannya sama dengan yang ditulis di `master-data.js`.
 */
const correctnessThresholds = {
    correctness_violations: ['count==0'],
    server_errors: ['count==0'],
};

// Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per host, dan
// penyiapan fixture penyusutan memuat dua belas tahap berurutan.
const batasBatch = { setupTimeout: '20m', batch: 64, batchPerHost: 32 };
const statistik = ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'];

export const options =
    PROFILE === 'finalize-race'
        ? {
              scenarios: { finalizeRace: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '20s' } },
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

function idDari(responses) {
    return responses.map((response) => response.json('data.id'));
}

export function setup() {
    const tenants = siapkanTenant(TENANT_COUNT, ['management-aset']);
    // Tenant tambahan yang role Owner-nya dipersempit ke satu duty, dipakai probe eskalasi hak.
    const sempit = sempitkanTenant(siapkanTenant(1, ['management-aset'], 'sempit')[0]);

    const params = (tenant, kunci) => paramsUntuk(tenant, {}, kunci ? { 'Idempotency-Key': kunci } : undefined);
    const semua = (fn) => tenants.map((tenant, index) => fn(bangunJar(tenant), index));
    // Kunci seed diikat ke FIXTURE, bukan ke RUN_ID: menjalankan ulang dengan fixture yang sama
    // memakai kembali aset, buku, dan ketiga periode yang sama. Kalau ia diikat ke RUN_ID, tiap
    // run membuat aset baru, `penyusutan/buku` memulangkan lebih dari satu baris, dan oracle
    // saldo kehilangan satu-satunya baris yang jawabannya diketahui.
    const kunci = (nama, index) => `dep-${FIXTURE}-${nama}-${index}`;

    const jenisIds = idDari(tahap('jenis-aset', semua((tenant, index) => ['POST', ASET('jenis-aset'), JSON.stringify({ nama: `Susut jenis ${index}` }), params(tenant, kunci('jenis', index))])));
    const groupIds = idDari(tahap('group-aset', semua((tenant, index) => ['POST', ASET('group-aset'), JSON.stringify({ nama: `Susut group ${index}` }), params(tenant, kunci('group', index))])));
    const profilIds = idDari(tahap(
        'profil-penyusutan',
        semua((tenant, index) => [
            'POST',
            ASET('profil-penyusutan'),
            JSON.stringify({
                nama: `Susut SLLR ${index}`,
                method: 'straight_line_life_remaining',
                frequency: 'monthly',
                year_basis: 'calendar',
                useful_life_periods: 3,
                convention: 'full_month',
            }),
            params(tenant, kunci('profil', index)),
        ]),
    ));
    // `export_to_backoffice: true` supaya finalisasi menerbitkan satu baris export. `posting_id`
    // pada baris itu adalah oracle idempotensi yang paling tajam: finalisasi yang diulang wajib
    // memulangkan posting yang SAMA, dan posting kedua berarti backoffice dijurnal dua kali.
    const bukuIds = idDari(tahap(
        'buku-penyusutan',
        semua((tenant, index) => [
            'POST',
            ASET('buku-penyusutan'),
            JSON.stringify({
                nama: `Susut buku ${index}`,
                posting_layer: 'current',
                export_to_backoffice: true,
                depreciation_profile_id: profilIds[index],
                round_off_depreciation: 10,
            }),
            params(tenant, kunci('buku', index)),
        ]),
    ));

    // Round-off 100 ditulis di baris matriks, bukan di buku: yang dipakai buku aset adalah nilai
    // matriks bila ada, dan selisih 10 versus 100 di atas memastikan baris inilah yang menang.
    tahap(
        'matriks group x buku',
        semua((tenant, index) => [
            'PUT',
            `${ASET('group-aset')}/${groupIds[index]}/buku-penyusutan`,
            JSON.stringify({
                rows: [{
                    buku_id: bukuIds[index],
                    depreciation_profile_id: profilIds[index],
                    useful_life_periods: 3,
                    convention: 'full_month',
                    depreciate: true,
                    round_off_depreciation: 100,
                }],
            }),
            params(tenant),
        ]),
        [200],
    );

    // Aset lahir dari dokumen penerimaan, bukan dari `POST /aset` — endpoint itu dibuang
    // 18 September 2026. Dua tahap karena itu: draf dulu, lalu diselesaikan.
    const penerimaan = tahap(
        'penerimaan',
        semua((tenant, index) => [
            'POST',
            ASET('penerimaan-aset'),
            JSON.stringify({
                legal_entity_id: tenant.legalEntityId,
                responsible_org_unit_id: tenant.orgUnitId,
                tanggal: '2026-01-01',
                tanggal_siap_pakai: '2026-01-01',
                currency_code: 'IDR',
                details: [
                    {
                        nama: `Aset susut ${index}`,
                        group_aset_id: groupIds[index],
                        jenis_aset_id: jenisIds[index],
                        jumlah: 1,
                        nilai_per_unit: 1000,
                        residu_per_unit: 0,
                    },
                ],
            }),
            params(tenant, kunci('penerimaan', index)),
        ]),
    );
    const penerimaanIds = idDari(penerimaan);
    tahap(
        'selesaikan penerimaan',
        semua((tenant, index) => [
            'POST',
            `${ASET('penerimaan-aset')}/${penerimaanIds[index]}/selesaikan`,
            JSON.stringify({ version: 1 }),
            params(tenant),
        ]),
        [200],
    );
    const aset = tahap(
        'aset dokumen',
        semua((tenant, index) => [
            'GET',
            `${ASET('penerimaan-aset')}/${penerimaanIds[index]}/aset`,
            null,
            params(tenant),
        ]),
        [200],
    );
    const kodeAset = aset.map((response) => String(response.json('data.0.kode')));

    // Buku aset dibentuk matriks saat aset diterima, bukan diminta terpisah. Ia dicari lewat
    // kode asetnya, bukan diambil `data.0`: tenant ini mungkin dipakai skenario lain dan
    // memiliki buku aset lainnya, dan `data.0` akan menunjuk baris yang salah.
    const bukuAset = tahap('buku aset', semua((tenant) => ['GET', ASET('penyusutan/buku'), null, params(tenant)]), [200]).map((response, index) => {
        const baris = (response.json('data') || []).find((row) => row.aset_code === kodeAset[index]);

        if (!baris) {
            fail(`setup buku aset tenant ${index} tidak terbentuk untuk aset ${kodeAset[index]}`);
        }

        return baris;
    });

    const periodeIds = [];
    const postingIds = [];

    for (let urutan = 0; urutan < PERIODE.length; urutan++) {
        const [mulai, selesai] = PERIODE[urutan];
        const proposal = tahap(
            `proposal periode ${urutan + 1}`,
            semua((tenant, index) => [
                'POST',
                ASET('penyusutan/proposal'),
                JSON.stringify({ buku_aset_id: bukuAset[index].id, period_starts_on: mulai, period_ends_on: selesai }),
                params(tenant),
            ]),
        );
        proposal.forEach((response, index) => {
            const nilai = Number(response.json('data.amount'));

            if (nilai !== NILAI[urutan]) {
                fail(`pembulatan tenant ${index} periode ${urutan + 1}: ${nilai} bukan ${NILAI[urutan]}`);
            }
        });
        periodeIds.push(idDari(proposal));

        const finalisasi = tahap(
            `finalisasi periode ${urutan + 1}`,
            semua((tenant, index) => ['POST', `${ASET('penyusutan')}/${periodeIds[urutan][index]}/finalisasi`, null, params(tenant)]),
            [200],
        );
        finalisasi.forEach((response, index) => {
            if (response.json('data.period.status') !== 'final') {
                fail(`finalisasi tenant ${index} periode ${urutan + 1} belum final`);
            }

            if (!response.json('data.export.posting_id')) {
                fail(`finalisasi tenant ${index} periode ${urutan + 1} tidak menerbitkan export`);
            }
        });
        postingIds.push(finalisasi.map((response) => String(response.json('data.export.posting_id'))));
    }

    tahap('saldo akhir', semua((tenant) => ['GET', ASET('penyusutan/buku'), null, params(tenant)]), [200]).forEach((response, index) => {
        const baris = (response.json('data') || []).find((row) => row.aset_code === kodeAset[index]);

        if (Number(baris.net_book_value) !== NILAI_BUKU_AKHIR || Number(baris.accumulated_depreciation) !== AKUMULASI_AKHIR) {
            fail(`saldo akhir tenant ${index} bukan NBV ${NILAI_BUKU_AKHIR} dengan akumulasi ${AKUMULASI_AKHIR}: ${baris.net_book_value}/${baris.accumulated_depreciation}`);
        }
    });

    console.log(`setup: ${tenants.length} tenant siap, ${PERIODE.length} periode final per tenant`);

    return {
        tenants: tenants.map((tenant, index) => ({
            ...tenant,
            asetCode: kodeAset[index],
            asetBookId: bukuAset[index].id,
            periodeIds: periodeIds.map((periode) => periode[index]),
            postingIds: postingIds.map((posting) => posting[index]),
        })),
        sempit,
    };
}

// ---------------------------------------------------------------- operasi

function recordFailure(response, label) {
    if (response.status === 0) {
        timeouts.add(1, { label });
    } else if (response.status === 502 || response.status === 504) {
        // Saturasi pada load balancer, bukan cacat aplikasi.
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

function tersedia(response) {
    return response.status !== 0 && response.status !== 502 && response.status !== 504;
}

function mintaProposal(tenant, mulai, selesai, extra) {
    return http.post(
        ASET('penyusutan/proposal'),
        JSON.stringify({ buku_aset_id: tenant.asetBookId, period_starts_on: mulai, period_ends_on: selesai }),
        paramsUntuk(tenant, { tags: { op: 'proposal', resource: 'penyusutan' }, ...extra }),
    );
}

function mintaFinalisasi(tenant, periodeId, extra) {
    return http.post(`${ASET('penyusutan')}/${periodeId}/finalisasi`, null, paramsUntuk(tenant, { tags: { op: 'finalisasi', resource: 'penyusutan' }, ...extra }));
}

/**
 * Proposal yang diulang untuk periode yang sudah ada wajib memulangkan periode yang SAMA dengan
 * nilai yang SAMA — bukan menghitung ulang, bukan membuat baris kedua.
 */
function proposalUlang(tenant, urutan) {
    // SELFTEST meminta periode di luar masa manfaat: periode yang lahir memang bukan periode
    // yang dicatat, dan pembandingnya wajib menangkapnya.
    const [mulai, selesai] = SELFTEST ? PERIODE_LUAR : PERIODE[urutan];
    const response = mintaProposal(tenant, mulai, selesai);
    record(response, writeLatency, 200, 'proposal ulang 200');

    if (!tersedia(response) || response.status >= 400) {
        return;
    }

    proposalReplays.add(1);

    if (response.json('data.id') !== tenant.periodeIds[urutan]) {
        violation('proposal_menghasilkan_periode_lain', { urutan: urutan + 1 });

        return;
    }

    if (Number(response.json('data.amount')) !== NILAI[urutan]) {
        violation('proposal_nilai_berubah', { urutan: urutan + 1, nilai: response.json('data.amount') });
    }
}

/**
 * Finalisasi yang diulang wajib memulangkan posting yang SAMA. Posting kedua berarti backoffice
 * menerima jurnal dua kali untuk satu periode penyusutan.
 */
function finalisasiUlang(tenant, urutan) {
    // SELFTEST menyuruh sistem memfinalkan periode di luar masa manfaat lebih dahulu, lalu
    // membandingkan postingnya dengan posting periode yang dicatat. Nilainya 0, jadi saldo tidak
    // ikut bergeser — yang dibuktikan hanya bahwa pembanding postingnya hidup.
    let periodeId = tenant.periodeIds[urutan];

    if (SELFTEST) {
        const luar = mintaProposal(tenant, PERIODE_LUAR[0], PERIODE_LUAR[1]);

        if (!tersedia(luar) || luar.status >= 400) {
            recordFailure(luar, 'selftest-proposal-luar');

            return;
        }

        periodeId = luar.json('data.id');
    }

    const response = mintaFinalisasi(tenant, periodeId);
    record(response, writeLatency, 200, 'finalisasi ulang 200');

    if (!tersedia(response) || response.status >= 400) {
        return;
    }

    finalizeReplays.add(1);

    if (response.json('data.period.status') !== 'final') {
        violation('periode_tidak_final', { urutan: urutan + 1 });
    }

    if (String(response.json('data.export.posting_id')) !== tenant.postingIds[urutan]) {
        violation('finalisasi_menerbitkan_posting_lain', { urutan: urutan + 1 });
    }
}

/**
 * Dua finalisasi periode yang sama, dikirim berbarengan. Keduanya wajib dijawab 200 dengan
 * posting yang sama: yang kalah menemukan periodenya sudah final dan memulangkan export yang
 * sudah ada, bukan menambah saldo untuk kedua kalinya.
 */
function balapanFinalisasi(tenant, urutan) {
    // SELFTEST menggerakkan dua periode berbeda: postingnya memang berbeda, dan pembandingnya
    // wajib menangkapnya.
    const lain = (urutan + 1) % PERIODE.length;
    const kiriId = tenant.periodeIds[urutan];
    const kananId = SELFTEST ? tenant.periodeIds[lain] : kiriId;
    const params = paramsUntuk(tenant, { tags: { op: 'finalisasi_race', resource: 'penyusutan' } });
    const [kiri, kanan] = http.batch([
        ['POST', `${ASET('penyusutan')}/${kiriId}/finalisasi`, null, params],
        ['POST', `${ASET('penyusutan')}/${kananId}/finalisasi`, null, params],
    ]);
    [kiri, kanan].forEach((response) => {
        writeLatency.add(response.timings.duration);
        recordFailure(response, 'finalisasi-race');
    });

    if (![kiri, kanan].every((response) => tersedia(response) && response.status === 200)) {
        return;
    }

    finalizeRaces.add(1);
    const sama = String(kiri.json('data.export.posting_id')) === String(kanan.json('data.export.posting_id'));

    if (!sama) {
        violation('finalisasi_serentak_dua_posting', { urutan: urutan + 1 });
    }

    check({ kiri, kanan }, { 'finalisasi serentak satu posting': () => sama });
}

/**
 * Saldo buku aset wajib tetap akumulasi 1.000 dan nilai buku 0, berapa kali pun proposal dan
 * finalisasi diulang dan seberapa pun berbarengan.
 */
function bacaSaldo(tenant) {
    // SELFTEST mengirim `reversal` lebih dahulu. Pembalikan adalah permintaan yang sah dan
    // memang menggeser saldo; sesudahnya pembacaan di bawah wajib memerah. Inilah satu-satunya
    // pembuktian merah di berkas ini yang merusak fixture — lihat catatan di kepala berkas.
    if (SELFTEST) {
        const balik = http.post(
            `${ASET('penyusutan')}/${tenant.periodeIds[0]}/reversal`,
            JSON.stringify({ reason: 'selftest oracle saldo' }),
            paramsUntuk(tenant, { tags: { op: 'reversal', resource: 'penyusutan' }, responseCallback: http.expectedStatuses(201, 409) }),
        );
        writeLatency.add(balik.timings.duration);
        recordFailure(balik, 'selftest-reversal');
    }

    const response = http.get(ASET('penyusutan/buku'), paramsUntuk(tenant, { tags: { op: 'saldo', resource: 'penyusutan' } }));
    record(response, readLatency, 200, 'saldo 200');

    if (response.status !== 200) {
        return;
    }

    const baris = (response.json('data') || []).find((row) => row.aset_code === tenant.asetCode);

    if (!baris) {
        violation('buku_aset_hilang_dari_daftar');

        return;
    }

    balanceReads.add(1);

    if (Number(baris.accumulated_depreciation) !== AKUMULASI_AKHIR || Number(baris.net_book_value) !== NILAI_BUKU_AKHIR) {
        violation('saldo_penyusutan_bergeser', { akumulasi: baris.accumulated_depreciation, nbv: baris.net_book_value });
    }
}

/** Daftar periode penyusutan tidak boleh memuat satu baris pun milik buku tenant lain. */
function bacaDaftar(data, tenant) {
    const response = http.get(ASET('penyusutan'), paramsUntuk(tenant, { tags: { op: 'list', resource: 'penyusutan' } }));
    record(response, readLatency, 200, 'daftar 200');

    if (response.status !== 200) {
        return;
    }

    const korban = data.tenants[(tenant.index + 1) % data.tenants.length];
    // SELFTEST mencari buku milik sendiri, yang memang ada di daftar ini, dan baris di bawahnya
    // wajib membacanya sebagai kebocoran.
    const dicari = SELFTEST ? tenant.asetBookId : korban.asetBookId;
    const asing = (response.json('data') || []).filter((row) => String(row.buku_aset_id) === String(dicari));

    if (asing.length > 0) {
        violation('daftar_penyusutan_memuat_buku_tenant_lain', { baris: asing.length });
    }
}

/** Sesi tenant A tidak boleh memfinalkan periode penyusutan tenant B. */
function probeLintasTenant(data, tenant) {
    crossTenantProbes.add(1);
    const korban = data.tenants[(tenant.index + 1) % data.tenants.length];
    // SELFTEST memfinalkan periode milik sendiri — yang sudah final, jadi jawabannya 200 tanpa
    // mengubah apa pun — dan baris di bawahnya wajib membacanya sebagai finalisasi lintas tenant.
    const sasaran = SELFTEST ? tenant.periodeIds[0] : korban.periodeIds[0];
    const response = mintaFinalisasi(tenant, sasaran, {
        tags: { op: 'probe_write', resource: 'penyusutan' },
        responseCallback: http.expectedStatuses(404),
    });
    writeLatency.add(response.timings.duration);
    recordFailure(response, 'probe-cross-finalisasi');

    if (response.status === 200) {
        violation('finalisasi_lintas_tenant_diterima');
    }
}

/** Hak pada satu master tidak boleh merembet ke permukaan penyusutan. */
function probeEskalasiHak(sempit) {
    scopeProbes.add(1);

    const boleh = http.get(`${ASET('group-aset')}?per_page=1`, paramsUntuk(sempit, { tags: { op: 'probe_scope_allowed', resource: 'group-aset' } }));

    // Hanya penolakan yang benar-benar dijawab server yang dihitung. Status 0 berarti klien
    // menyerah menunggu — itu kapasitas, bukan hak yang dicabut.
    if (boleh.status === 401 || boleh.status === 403 || boleh.status === 404) {
        violation('permission_scope_denied_wrongly', { status: boleh.status });
    }

    const ditolak = http.get(ASET('penyusutan'), paramsUntuk(sempit, {
        tags: { op: 'probe_scope_denied', resource: 'penyusutan' },
        responseCallback: http.expectedStatuses(403),
    }));
    readLatency.add(ditolak.timings.duration);
    recordFailure(ditolak, 'probe-scope');

    if (ditolak.status === 200) {
        violation('penyusutan_permission_escalation');
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
    const urutan = exec.scenario.iterationInTest % PERIODE.length;

    if (PROFILE === 'finalize-race') {
        balapanFinalisasi(tenant, urutan);

        return;
    }

    const roll = Math.random();

    if (roll < 0.28) {
        proposalUlang(tenant, urutan);
    } else if (roll < 0.5) {
        finalisasiUlang(tenant, urutan);
    } else if (roll < 0.66) {
        balapanFinalisasi(tenant, urutan);
    } else if (roll < 0.8) {
        bacaSaldo(tenant);
    } else if (roll < 0.9) {
        bacaDaftar(data, tenant);
    } else if (roll < 0.97) {
        probeLintasTenant(data, tenant);
    } else {
        // Pada SELFTEST probe eskalasi memakai sesi yang memang berhak penuh; daftar penyusutan
        // dijawab 200, dan itu harus terbaca sebagai eskalasi.
        probeEskalasiHak(SELFTEST ? tenant : tenantSempit(data.sempit));
    }
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];

        return value === undefined ? null : Number(value.toFixed(2));
    };

    const summary = {
        skenario: 'depreciation',
        profile: PROFILE,
        run_id: RUN_ID,
        fixture: FIXTURE,
        selftest: SELFTEST,
        vus_configured: PROFILE === 'latency' ? Number(__ENV.LATENCY_VUS || 16) : VUS,
        tenants: TENANT_COUNT,
        race_arenas: PROFILE === 'finalize-race' ? RACE_TENANTS : 0,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: data.metrics.iterations?.values?.count ?? 0,
        requests: data.metrics.http_reqs?.values?.count ?? 0,
        throughput_rps: metric('http_reqs', 'rate'),
        kebenaran: {
            correctness_violations: data.metrics.correctness_violations?.values?.count ?? 0,
            server_errors: data.metrics.server_errors?.values?.count ?? 0,
            proposal_replays: data.metrics.proposal_replays?.values?.count ?? 0,
            finalize_replays: data.metrics.finalize_replays?.values?.count ?? 0,
            finalize_races: data.metrics.finalize_races?.values?.count ?? 0,
            balance_reads: data.metrics.balance_reads?.values?.count ?? 0,
            cross_tenant_probes: data.metrics.cross_tenant_probes?.values?.count ?? 0,
            permission_scope_probes: data.metrics.permission_scope_probes?.values?.count ?? 0,
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
        stdout: `\n===== RINGKASAN LOAD TEST depreciation (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-depreciation-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
