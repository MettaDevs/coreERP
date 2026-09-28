// Load test work order pemeliharaan Management Aset, pada runtime Core yang sungguhan.
//
// Sampai F7-03 berkas ini berhenti dengan galat di baris `open('./tenants.json')`: ia masih
// memakai berkas fixture berisi token konteks dan header `Authorization: Bearer`, dan keduanya
// tidak ada lagi sejak module masuk ke dalam runtime Core. Sekarang bentuknya sama dengan
// `master-data.js` dan `maintenance.js`: tenant disiapkan lewat alur pendaftaran usaha yang
// sungguhan di dalam `setup()`, identitasnya cookie sesi Core, dan rutenya berawalan
// `/api/modules/management-aset/v1/`.
//
// Empat profil, karena permukaan ini punya empat pertanyaan berbeda:
//
//   PROFILE=saturation      Beban serentak pada seluruh siklus work order. Yang digate:
//                           KEBENARAN. Tidak boleh ada 5xx aplikasi, kebocoran lintas tenant,
//                           eskalasi hak, transisi terlarang yang diterima, atau idempotency
//                           yang pecah.
//
//   PROFILE=latency         Concurrency tertahan; yang digate p95/p99 per jenis operasi.
//
//   PROFILE=transition-race Yang paling penting di berkas ini. Beberapa VU memindahkan status
//                           work order yang SAMA dari versi yang sama secara bersamaan.
//                           `pindahStatus` membaca sekilas di luar transaksi lalu mengunci
//                           ulang di dalamnya; kalau kuncinya tidak menahan, dua permintaan
//                           dapat sama-sama menang dan dokumen melompati satu status tanpa
//                           menyisakan baris status log yang menjelaskannya. Tidak ada batasan
//                           basis data yang menolaknya — tiap baris masing-masing sah — dan
//                           feature test tidak akan pernah melihatnya karena ia menjalankan
//                           satu permintaan pada satu proses.
//
// Satu VU melayani satu tenant sepanjang run, karena identitasnya sebuah sesi dan sesi itu
// milik satu pengguna.

import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { FIXTURE, RUN_ID, bangunJar, paramsUntuk, sempitkanTenant, siapkanTenant, tenantVu, urlModule } from '../lib.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const DURATION = __ENV.DURATION || '90s';
// Balapan transisi sengaja dipusatkan pada sedikit tenant: menyebarnya ke 128 tenant membuat
// dua permintaan hampir tidak pernah bertemu pada dokumen yang sama, dan run kembali hijau
// tanpa membuktikan apa pun.
const RACE_TENANTS = Number(__ENV.RACE_TENANTS || 4);
const TENANT_COUNT = PROFILE === 'transition-race' ? RACE_TENANTS : Number(__ENV.TENANTS || 128);

const ASET = (path) => urlModule('management-aset', path);
const WO = ASET('pemeliharaan-aset');

/*
 * Mode pembuktian oracle; penjelasan panjangnya ada di `master-data.js`.
 *
 * `SELFTEST=1` merusak PERMINTAAN, bukan produknya:
 *
 *   - probe baca lintas tenant diarahkan ke work order milik sendiri;
 *   - probe eskalasi hak memakai sesi yang memang berhak penuh;
 *   - probe transisi terlarang mengirim transisi yang justru sah;
 *   - balapan idempotency mengirim dua kunci yang berbeda;
 *   - balapan transisi menggerakkan dua dokumen berbeda, bukan satu dokumen dua kali.
 *
 * Kelimanya lalu WAJIB menaikkan pencacah pelanggaran dan membuat run merah. Yang dibuktikan
 * karena itu adalah pendeteksinya hidup — bukan bahwa produknya cacat.
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
const crossTenantProbes = new Counter('cross_tenant_probes');
const scopeProbes = new Counter('permission_scope_probes');
const illegalProbes = new Counter('illegal_transition_probes');
const idempotencyReplays = new Counter('idempotency_replays');
// Dua permintaan transisi dari versi yang sama sama-sama dijawab 200. Inilah cacat yang
// dijaga profil `transition-race`.
const doubleWins = new Counter('transition_double_wins');
const transitionConflicts = new Counter('transition_conflicts');
const transitionRaces = new Counter('transition_races');
const lifecycles = new Counter('work_order_lifecycles');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

/*
 * Gate kebenaran, dan hanya gate kebenaran.
 *
 * `http_req_failed` dan `checks` sengaja TIDAK ada di sini. Pada beban jenuh nginx menjawab 504
 * karena antrean penuh, dan tiap 504 menaikkan keduanya — sehingga run yang kapasitasnya
 * terlampaui terlihat sama merahnya dengan run yang datanya bocor. Angka kapasitas tetap
 * dicetak di ringkasan, dinamai sebagai kapasitas.
 */
const correctnessThresholds = {
    correctness_violations: ['count==0'],
    transition_double_wins: ['count==0'],
    server_errors: ['count==0'],
};

// Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per host, dan
// dengan 128 tenant itu membuat setup lebih lama daripada run-nya.
const batasBatch = { setupTimeout: '15m', batch: 64, batchPerHost: 32 };
const statistik = ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'];

export const options =
    PROFILE === 'transition-race'
        ? {
              scenarios: { transitionRace: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '20s' } },
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
                // Beban jenuh memang memaksa antrean panjang; batalkan hanya bila benar-benar macet.
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

    return responses.map((response) => response.json('data.id'));
}

/**
 * Badan work order. Jadwal mulai wajib terisi sebelum dokumen dapat dijadwalkan, jadi ia
 * selalu dikirim; tanpa itu transisi pertama dijawab 422 dan seluruh siklus diam-diam
 * berhenti diuji.
 */
function badanWorkOrder(tenant, keterangan) {
    return JSON.stringify({
        legal_entity_id: tenant.legalEntityId,
        responsible_org_unit_id: tenant.orgUnitId,
        tipe_work_order_id: tenant.tipeWorkOrderId,
        keterangan,
        diharapkan_mulai: '2026-01-01 08:00:00',
        diharapkan_selesai: '2026-01-01 10:00:00',
        dijadwalkan_mulai: '2026-01-01 08:00:00',
        dijadwalkan_selesai: '2026-01-01 10:00:00',
        details: [{
            aset_id: tenant.asetId,
            maintenance_job_type_id: tenant.jobTypeId,
            estimasi_jam: 1,
            catatan: 'load test work order',
        }],
    });
}

export function setup() {
    const tenants = siapkanTenant(TENANT_COUNT, ['management-aset']);
    // Tenant tambahan yang role Owner-nya dipersempit ke satu duty. Dipakai membuktikan batas
    // hak tetap tegak saat sistem jenuh: pemegangnya boleh melist master group aset dan wajib
    // ditolak pada permukaan work order.
    const sempit = sempitkanTenant(siapkanTenant(1, ['management-aset'], 'sempit')[0]);

    const params = (tenant, kunci) => paramsUntuk(tenant, {}, kunci ? { 'Idempotency-Key': kunci } : undefined);
    const semua = (fn) => tenants.map((tenant, index) => fn(bangunJar(tenant), index));
    // Kunci seed diikat ke FIXTURE, bukan ke RUN_ID: menjalankan ulang dengan fixture yang sama
    // memakai kembali aset dan master yang sama, bukan menumbuhkan data seed tiap run.
    const kunci = (nama, index) => `wo-${FIXTURE}-${nama}-${index}`;

    const groupIds = tahap('group-aset', semua((tenant, index) => ['POST', ASET('group-aset'), JSON.stringify({ nama: `WO group ${index}` }), params(tenant, kunci('group', index))]));
    const jenisIds = tahap('jenis-aset', semua((tenant, index) => ['POST', ASET('jenis-aset'), JSON.stringify({ nama: `WO jenis ${index}` }), params(tenant, kunci('jenis', index))]));
    // Tipe work order dibiarkan tanpa `satu_pekerja`: aturan satu pelaksana menolak penjadwalan
    // dokumen yang barisnya belum ditugaskan, dan skenario ini menguji mesin status, bukan
    // penugasan.
    const tipeIds = tahap('tipe-work-order', semua((tenant, index) => ['POST', ASET('tipe-work-order'), JSON.stringify({ nama: `WO tipe ${index}` }), params(tenant, kunci('tipe', index))]));
    // Job type dibiarkan tanpa kaitan jenis aset. Begitu satu job type dikaitkan, pilihannya
    // mengikuti relasi F&O dan aset di luar relasi itu ditolak 422 — perilaku yang benar, tetapi
    // bukan yang diukur di sini.
    const jobTypeIds = tahap('maintenance-job-types', semua((tenant, index) => ['POST', ASET('maintenance-job-types'), JSON.stringify({ nama: `WO job type ${index}` }), params(tenant, kunci('job', index))]));

    const asetIds = tahap(
        'aset',
        semua((tenant, index) => [
            'POST',
            ASET('aset'),
            JSON.stringify({
                nama: `Aset WO ${index}`,
                legal_entity_id: tenant.legalEntityId,
                usage_org_unit_id: tenant.orgUnitId,
                group_aset_id: groupIds[index],
                jenis_aset_id: jenisIds[index],
                acquired_on: '2026-01-01',
                acquisition_value: 1000000,
                currency_code: 'IDR',
            }),
            params(tenant, kunci('aset', index)),
        ]),
    );

    const lengkap = tenants.map((tenant, index) => ({
        ...tenant,
        groupAsetId: groupIds[index],
        jenisAsetId: jenisIds[index],
        tipeWorkOrderId: tipeIds[index],
        jobTypeId: jobTypeIds[index],
        asetId: asetIds[index],
    }));

    // Satu work order tetap per tenant, dipakai probe baca lintas tenant dan pembacaan `show`.
    // Ia sengaja dibiarkan draf supaya tidak ada iterasi yang mengubah statusnya.
    const seedIds = tahap(
        'work order seed',
        lengkap.map((tenant, index) => ['POST', WO, badanWorkOrder(tenant, 'seed load test'), params(bangunJar(tenant), kunci('seed', index))]),
    );

    console.log(`setup: ${lengkap.length} tenant siap + 1 tenant berhak sempit`);

    return { tenants: lengkap.map((tenant, index) => ({ ...tenant, seedWorkOrderId: seedIds[index] })), sempit };
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

function buatWorkOrder(tenant, kunci, keterangan, extra) {
    return http.post(WO, badanWorkOrder(tenant, keterangan), paramsUntuk(tenant, { tags: { op: 'create', resource: 'pemeliharaan-aset' }, ...extra }, { 'Idempotency-Key': kunci }));
}

function pindahStatus(tenant, id, keStatus, version, extra) {
    return http.post(
        `${WO}/${id}/status`,
        JSON.stringify({ ke_status: keStatus, version }),
        paramsUntuk(tenant, { tags: { op: 'transition', resource: 'pemeliharaan-aset', status: keStatus }, ...extra }),
    );
}

const kunciIterasi = (nama) => `wo-${RUN_ID}-${nama}-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;

/**
 * Siklus penuh satu dokumen: draf, dijadwalkan, dikerjakan, hasil pelaksanaan, selesai.
 *
 * Versi dokumen dipakai apa adanya dari jawaban sebelumnya, bukan dihitung 1-2-3 di klien.
 * Menebak versi membuat langkah berikutnya dijawab 409 setiap kali urutan nomornya berubah,
 * dan kegagalan itu akan terbaca sebagai cacat kunci optimistik padahal hanya salah tebak.
 */
function siklus(tenant) {
    const dibuat = buatWorkOrder(tenant, kunciIterasi('life'), 'siklus load test');
    record(dibuat, writeLatency, 201, 'work order dibuat 201');

    if (dibuat.status !== 201) {
        return;
    }

    const id = dibuat.json('data.id');

    if (!String(dibuat.json('data.kode')).startsWith('PMHA')) {
        violation('wrong_sequence_prefix_on_work_order');
    }

    if (String(dibuat.json('data.tenant_id')) !== String(tenant.id)) {
        violation('work_order_written_to_other_tenant');
    }

    const dijadwalkan = pindahStatus(tenant, id, 'dijadwalkan', Number(dibuat.json('data.version')));
    record(dijadwalkan, writeLatency, 200, 'dijadwalkan 200');

    if (dijadwalkan.status !== 200) {
        return;
    }

    if (dijadwalkan.json('data.status') !== 'dijadwalkan') {
        violation('status_tidak_berpindah', { ke: 'dijadwalkan' });
    }

    const dikerjakan = pindahStatus(tenant, id, 'dikerjakan', Number(dijadwalkan.json('data.version')));
    record(dikerjakan, writeLatency, 200, 'dikerjakan 200');

    if (dikerjakan.status !== 200) {
        return;
    }

    const dibaca = http.get(`${WO}/${id}`, paramsUntuk(tenant, { tags: { op: 'show', resource: 'pemeliharaan-aset' } }));
    record(dibaca, readLatency, 200, 'show 200');

    if (dibaca.status !== 200) {
        return;
    }

    const barisPekerjaan = dibaca.json('data.details.0.id');

    if (!barisPekerjaan) {
        violation('work_order_tanpa_baris_pekerjaan');

        return;
    }

    const pelaksanaan = http.patch(
        `${WO}/${id}/jobs/${barisPekerjaan}/execution`,
        JSON.stringify({ aktual_jam: 1 }),
        paramsUntuk(tenant, { tags: { op: 'execution', resource: 'pemeliharaan-aset' } }),
    );
    record(pelaksanaan, writeLatency, 200, 'hasil pelaksanaan 200');

    if (pelaksanaan.status !== 200) {
        return;
    }

    const selesai = pindahStatus(tenant, id, 'selesai', Number(pelaksanaan.json('data.version')));
    record(selesai, writeLatency, 200, 'selesai 200');

    if (selesai.status === 200) {
        lifecycles.add(1);

        if (selesai.json('data.status') !== 'selesai') {
            violation('status_tidak_berpindah', { ke: 'selesai' });
        }
    }
}

/**
 * Transisi yang tidak ada di grafik status wajib ditolak 422, juga ketika sistem jenuh.
 *
 * Dokumen draf tidak boleh langsung menjadi selesai: yang menahannya hanya kode, tidak ada
 * satu pun batasan basis data yang ikut menolaknya.
 */
function probeTransisiTerlarang(tenant) {
    illegalProbes.add(1);
    const dibuat = buatWorkOrder(tenant, kunciIterasi('illegal'), 'probe transisi terlarang');

    if (dibuat.status !== 201) {
        recordFailure(dibuat, 'probe-illegal');

        return;
    }

    // SELFTEST mengirim transisi yang justru sah; ia dijawab 200, dan baris di bawahnya wajib
    // membacanya sebagai transisi terlarang yang diterima.
    const ke = SELFTEST ? 'dijadwalkan' : 'selesai';
    const ditolak = pindahStatus(tenant, dibuat.json('data.id'), ke, Number(dibuat.json('data.version')), {
        responseCallback: http.expectedStatuses(422),
    });
    writeLatency.add(ditolak.timings.duration);
    recordFailure(ditolak, 'probe-illegal');

    if (ditolak.status === 200) {
        violation('transisi_terlarang_diterima', { ke });
    }
}

/**
 * Dua permintaan transisi dari versi yang sama, dikirim berbarengan pada dokumen yang sama.
 * Tepat satu boleh menang; yang lain wajib 409. Kalau keduanya 200, kunci di dalam transaksi
 * tidak menahan dan dokumen berpindah dua kali dari satu versi.
 */
function balapanTransisi(tenant) {
    const pertama = buatWorkOrder(tenant, kunciIterasi('race-a'), 'balapan transisi');

    if (pertama.status !== 201) {
        recordFailure(pertama, 'race-create');

        return;
    }

    // SELFTEST menggerakkan dua dokumen berbeda, masing-masing dari versinya sendiri: keduanya
    // sah, keduanya dijawab 200, dan pembandingnya wajib menangkapnya sebagai dua pemenang.
    const kedua = SELFTEST ? buatWorkOrder(tenant, kunciIterasi('race-b'), 'balapan transisi selftest') : pertama;

    if (kedua.status !== 201) {
        recordFailure(kedua, 'race-create');

        return;
    }

    // Keduanya dokumen yang baru dibuat, jadi versinya sama; badan permintaannya karena itu
    // dapat dipakai bersama tanpa menebak nomor versi.
    const badan = JSON.stringify({ ke_status: 'dijadwalkan', version: Number(pertama.json('data.version')) });
    const params = paramsUntuk(tenant, {
        tags: { op: 'transition_race', resource: 'pemeliharaan-aset' },
        // 409 dan 422 dua-duanya jawaban yang diharapkan dari yang kalah; menandainya sebagai
        // permintaan gagal hanya mengotori angka kapasitas.
        responseCallback: http.expectedStatuses(200, 409, 422),
    });
    const [kiri, kanan] = http.batch([
        ['POST', `${WO}/${pertama.json('data.id')}/status`, badan, params],
        ['POST', `${WO}/${kedua.json('data.id')}/status`, badan, params],
    ]);
    [kiri, kanan].forEach((response) => {
        writeLatency.add(response.timings.duration);
        recordFailure(response, 'transition-race');
    });

    const tidakTersedia = [kiri, kanan].some((response) => response.status === 0 || response.status === 502 || response.status === 504);

    if (tidakTersedia) {
        return;
    }

    transitionRaces.add(1);
    const menang = [kiri, kanan].filter((response) => response.status === 200).length;

    if (menang === 2) {
        doubleWins.add(1);
        console.error(`transition_double_wins: ${kiri.status}/${kanan.status}`);
    }

    // Yang kalah punya dua bentuk, dan keduanya penolakan yang benar: 409 bila versinya sudah
    // basah saat kunci di dalam transaksi diperiksa, atau 422 bila dokumennya sudah berpindah
    // sebelum pembacaan sekilas di luar transaksi sempat membacanya. Keduanya dihitung sama:
    // yang dijaga adalah "tepat satu menang", bukan bentuk penolakan yang kebetulan muncul.
    if (menang === 1) {
        transitionConflicts.add(1, { kalah: String([kiri, kanan].find((response) => response.status !== 200).status) });
    }

    check({ kiri, kanan }, { 'tepat satu transisi menang': () => menang === 1 });
}

/** Dua permintaan identik berbarengan harus menghasilkan tepat satu work order. */
function balapanIdempotency(tenant) {
    const kunci = kunciIterasi('idem');
    const badan = badanWorkOrder(tenant, 'balapan idempotency');
    const params = paramsUntuk(tenant, { tags: { op: 'race', resource: 'pemeliharaan-aset' } }, { 'Idempotency-Key': kunci });
    // SELFTEST memberi kunci yang berbeda pada permintaan kedua: dua record memang terbentuk,
    // dan pembandingnya wajib menangkapnya.
    const paramsKedua = SELFTEST
        ? paramsUntuk(tenant, { tags: { op: 'race', resource: 'pemeliharaan-aset' } }, { 'Idempotency-Key': `${kunci}-selftest` })
        : params;

    const [kiri, kanan] = http.batch([
        ['POST', WO, badan, params],
        ['POST', WO, badan, paramsKedua],
    ]);
    [kiri, kanan].forEach((response) => writeLatency.add(response.timings.duration));
    const sukses = (response) => response.status >= 200 && response.status < 300;

    if (!sukses(kiri) || !sukses(kanan)) {
        recordFailure(kiri, 'idem-race');
        recordFailure(kanan, 'idem-race');

        return;
    }

    if (kiri.json('data.id') !== kanan.json('data.id')) {
        violation('work_order_idempotency_produced_two_records');
    } else if (kiri.status === 200 || kanan.status === 200) {
        idempotencyReplays.add(1);
    }
}

/** Sesi tenant A tidak boleh menyentuh work order tenant B, baca maupun transisi. */
function probeLintasTenant(data, tenant) {
    crossTenantProbes.add(1);
    const korban = data.tenants[(tenant.index + 1) % data.tenants.length];
    // SELFTEST membaca dokumen milik sendiri, yang wajib 200 dan wajib dihitung sebagai
    // pelanggaran oleh baris di bawahnya.
    const sasaran = SELFTEST ? tenant.seedWorkOrderId : korban.seedWorkOrderId;

    const dibaca = http.get(`${WO}/${sasaran}`, paramsUntuk(tenant, {
        tags: { op: 'probe_read', resource: 'pemeliharaan-aset' },
        responseCallback: http.expectedStatuses(404),
    }));
    readLatency.add(dibaca.timings.duration);
    recordFailure(dibaca, 'probe-cross-read');

    if (dibaca.status === 200) {
        violation('work_order_cross_tenant_read');
    }

    // SELFTEST memindahkan status dokumen draf milik sendiri, yang dibuat khusus untuk itu:
    // ia dijawab 200, dan baris di bawahnya wajib membacanya sebagai transisi lintas tenant.
    // Dokumen baru, bukan dokumen seed, supaya setiap iterasi berangkat dari versi 1 dan
    // buktinya tidak hanya muncul sekali per tenant.
    let sasaranTulis = korban.seedWorkOrderId;

    if (SELFTEST) {
        const milik = buatWorkOrder(tenant, kunciIterasi('probe-tulis'), 'probe tulis selftest');

        if (milik.status !== 201) {
            recordFailure(milik, 'probe-cross-write');

            return;
        }

        sasaranTulis = milik.json('data.id');
    }

    const dipindah = pindahStatus(tenant, sasaranTulis, 'dijadwalkan', 1, {
        tags: { op: 'probe_write', resource: 'pemeliharaan-aset' },
        responseCallback: http.expectedStatuses(404),
    });
    writeLatency.add(dipindah.timings.duration);
    recordFailure(dipindah, 'probe-cross-write');

    if (dipindah.status === 200) {
        violation('work_order_cross_tenant_transition');
    }
}

/** Hak pada satu master tidak boleh merembet ke permukaan work order. */
function probeEskalasiHak(sempit) {
    scopeProbes.add(1);

    const boleh = http.get(`${ASET('group-aset')}?per_page=1`, paramsUntuk(sempit, { tags: { op: 'probe_scope_allowed', resource: 'group-aset' } }));

    // Hanya penolakan yang benar-benar dijawab server yang dihitung. Status 0 berarti klien
    // menyerah menunggu — itu kapasitas, bukan hak yang dicabut.
    if (boleh.status === 401 || boleh.status === 403 || boleh.status === 404) {
        violation('permission_scope_denied_wrongly', { status: boleh.status });
    }

    const ditolak = http.get(WO, paramsUntuk(sempit, {
        tags: { op: 'probe_scope_denied', resource: 'pemeliharaan-aset' },
        responseCallback: http.expectedStatuses(403),
    }));
    readLatency.add(ditolak.timings.duration);
    recordFailure(ditolak, 'probe-scope');

    if (ditolak.status === 200) {
        violation('work_order_permission_escalation');
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
    const tenants = data.tenants;
    // Satu VU, satu tenant: sesinya milik satu pengguna, dan cookie-nya dibangun sekali.
    const tenant = tenantVu(tenants, exec.vu.idInTest);

    if (PROFILE === 'transition-race') {
        balapanTransisi(tenant);

        return;
    }

    const roll = Math.random();

    if (roll < 0.3) {
        siklus(tenant);
    } else if (roll < 0.45) {
        const daftar = http.get(WO, paramsUntuk(tenant, { tags: { op: 'list', resource: 'pemeliharaan-aset' } }));
        record(daftar, readLatency, 200, 'daftar 200');

        if (daftar.status === 200) {
            const asing = (daftar.json('data') || []).filter((row) => !String(row.kode).startsWith('PMHA'));

            if (asing.length > 0) {
                violations.add(asing.length, { kind: 'wrong_sequence_prefix_in_list' });
            }
        }
    } else if (roll < 0.55) {
        const dibaca = http.get(`${WO}/${tenant.seedWorkOrderId}`, paramsUntuk(tenant, { tags: { op: 'show', resource: 'pemeliharaan-aset' } }));
        record(dibaca, readLatency, 200, 'show seed 200');
    } else if (roll < 0.67) {
        balapanIdempotency(tenant);
    } else if (roll < 0.79) {
        balapanTransisi(tenant);
    } else if (roll < 0.9) {
        probeTransisiTerlarang(tenant);
    } else if (roll < 0.97) {
        probeLintasTenant(data, tenant);
    } else {
        // Pada SELFTEST probe eskalasi memakai sesi yang memang berhak penuh; daftar work order
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
        skenario: 'work-order',
        profile: PROFILE,
        run_id: RUN_ID,
        fixture: FIXTURE,
        selftest: SELFTEST,
        vus_configured: PROFILE === 'latency' ? Number(__ENV.LATENCY_VUS || 16) : VUS,
        tenants: TENANT_COUNT,
        race_arenas: PROFILE === 'transition-race' ? RACE_TENANTS : 0,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: data.metrics.iterations?.values?.count ?? 0,
        requests: data.metrics.http_reqs?.values?.count ?? 0,
        throughput_rps: metric('http_reqs', 'rate'),
        kebenaran: {
            correctness_violations: data.metrics.correctness_violations?.values?.count ?? 0,
            transition_double_wins: data.metrics.transition_double_wins?.values?.count ?? 0,
            server_errors: data.metrics.server_errors?.values?.count ?? 0,
            transition_races: data.metrics.transition_races?.values?.count ?? 0,
            transition_conflicts: data.metrics.transition_conflicts?.values?.count ?? 0,
            illegal_transition_probes: data.metrics.illegal_transition_probes?.values?.count ?? 0,
            cross_tenant_probes: data.metrics.cross_tenant_probes?.values?.count ?? 0,
            permission_scope_probes: data.metrics.permission_scope_probes?.values?.count ?? 0,
            idempotency_replays: data.metrics.idempotency_replays?.values?.count ?? 0,
            work_order_lifecycles: data.metrics.work_order_lifecycles?.values?.count ?? 0,
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
        stdout: `\n===== RINGKASAN LOAD TEST work-order (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-work-order-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
