// Load test master data Management Aset, dijalankan pada runtime Core yang sungguhan.
//
// Berkasnya tetap di folder module karena permukaan yang diuji milik module; yang berubah
// adalah stack di bawahnya. Sejak F7-03 ia berjalan di atas `apps/control-plane/loadtest/`:
// satu compose, empat instance Core di belakang nginx, satu PostgreSQL, dan TIDAK ADA tiruan
// Core. Nomor diterbitkan `PenerbitNomor` di dalam proses yang sama, jadi oracle nomor pindah
// dari `/__stats` milik stub ke tabel `number_sequence_issues` — lihat `verify.sql`.
//
// Dua profil, karena keduanya menjawab pertanyaan berbeda:
//
//   PROFILE=saturation  1000+ VU serentak, 100+ tenant, 4 instance.
//                       Yang digate: KEBENARAN. Tidak boleh ada 5xx aplikasi, kebocoran
//                       lintas tenant, eskalasi hak, atau idempotency yang pecah.
//
//   PROFILE=latency     Concurrency tertahan; yang digate p95/p99 per jenis operasi.
//                       Latensi pada beban jenuh adalah kedalaman antrean, bukan biaya kode.
//
// Satu VU melayani satu tenant sepanjang run, karena identitasnya sekarang sebuah sesi dan
// sesi itu milik satu pengguna. Yang dulu dilakukan dengan menukar token per iterasi kini
// dilakukan dengan menukar VU.

import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { siapkanTenant, sempitkanTenant, paramsUntuk, bangunJar, tenantVu, urlModule, RUN_ID } from '../lib.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const TENANT_COUNT = Number(__ENV.TENANTS || 128);
const DURATION = __ENV.DURATION || '90s';

const ASET = (path) => urlModule('management-aset', path);

/*
 * Mode pembuktian oracle.
 *
 * Uji beban yang oracle-nya tidak pernah bisa merah selalu hijau, dan hijau semacam itu tidak
 * berarti apa-apa. `SELFTEST=1` merusak PERMINTAAN, bukan produknya: probe lintas tenant
 * diarahkan ke record milik sendiri, dan probe eskalasi hak memakai sesi yang memang berhak.
 * Keduanya lalu WAJIB menaikkan `correctness_violations` dan membuat run merah. Run seperti itu
 * dijalankan sekali, hasilnya disimpan, dan itulah bukti bahwa nol pada run sungguhan adalah
 * nol yang diperiksa — bukan pemeriksa yang mati.
 */
const SELFTEST = __ENV.SELFTEST === '1';

// Hanya model-aset yang masih berinduk setelah rantai klasifikasi diratakan.
const CHAINED = {
    'model-aset': { parentField: 'pabrikan_aset_id', seed: 'pabrikanAsetId' },
};
const STANDALONE = ['group-aset', 'jenis-aset', 'kondisi-aset', 'pabrikan-aset', 'item-checklist-maintenance', 'analisa-maintenance', 'tipe-lokasi-aset'];
// Prefix mengikuti `default_prefix` pada app.yaml, yang sejak pemindahan tersimpan di
// `app_number_sequence_references` dan dibaca Number Sequence Core.
const KODE_PREFIX = {
    'model-aset': 'MDLA',
    'group-aset': 'GRPA',
    'jenis-aset': 'JNSA',
    'kondisi-aset': 'KNDA',
    'pabrikan-aset': 'PBRA',
    'item-checklist-maintenance': 'ICMA',
    'analisa-maintenance': 'ANMA',
    'tipe-lokasi-aset': 'TLKA',
};

const readLatency = new Trend('op_read', true);
const writeLatency = new Trend('op_write', true);
const violations = new Counter('correctness_violations');
const serverErrors = new Counter('server_errors');
const gatewayErrors = new Counter('gateway_errors');
const idempotencyReplays = new Counter('idempotency_replays');
const crossTenantProbes = new Counter('cross_tenant_probes');
const scopeProbes = new Counter('permission_scope_probes');
// Status 0 berarti klien menyerah sebelum server menjawab: itu batas kapasitas, bukan
// jawaban salah. Dihitung terpisah agar tidak tertukar dengan kesalahan kebenaran.
const timeouts = new Counter('client_timeouts');

const LATENCY_SLO = {
    op_read: ['p(95)<200', 'p(99)<500'],
    op_write: ['p(95)<400', 'p(99)<900'],
};

/*
 * Gate kebenaran, dan hanya gate kebenaran.
 *
 * `http_req_failed` dan `checks` sengaja TIDAK ada di sini. Pada beban jenuh nginx menjawab
 * 504 karena antrean penuh, dan tiap 504 menaikkan keduanya — sehingga run yang kapasitasnya
 * terlampaui terlihat sama merahnya dengan run yang datanya bocor. Yang dipakai sebagai gate
 * adalah pencacah yang hanya naik ketika jawaban benar-benar SALAH; angka kapasitas tetap
 * dicetak di ringkasan, dinamai sebagai kapasitas.
 */
const correctnessThresholds = {
    correctness_violations: ['count==0'],
    server_errors: ['count==0'],
};

export const options =
    PROFILE === 'attribute-race'
        ? {
              scenarios: { attributeRace: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '20s' } },
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
              scenarios: { saturation: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '45s' } },
              thresholds: correctnessThresholds,
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
              // Beban jenuh memang memaksa antrean panjang; batalkan hanya bila benar-benar macet.
              setupTimeout: '15m',
              // Penyiapan tenant memakai http.batch; bawaan k6 hanya 6 permintaan serentak per
              // host, dan dengan 128 tenant itu membuat setup lebih lama daripada run-nya.
              batch: 64,
              batchPerHost: 32,
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

export function setup() {
    const tenants = siapkanTenant(TENANT_COUNT, ['management-aset']);
    // Tenant tambahan yang role Owner-nya dipersempit ke satu duty. Dipakai membuktikan batas
    // hak antar master tetap tegak saat sistem jenuh.
    const sempit = sempitkanTenant(siapkanTenant(1, ['management-aset'], 'sempit')[0]);

    const params = (tenant, kunci) => paramsUntuk(tenant, {}, kunci ? { 'Idempotency-Key': kunci } : undefined);
    const semua = (fn) => tenants.map((tenant, index) => fn(bangunJar(tenant), index));

    // Satuan disediakan Core per tenant saat pendaftaran; idnya berbeda tiap tenant.
    const satuan = http.batch(semua((tenant) => ['GET', ASET('reference-data/units-of-measure'), null, params(tenant)]))
        .map((response, index) => {
            if (response.status !== 200) {
                fail(`setup satuan gagal pada tenant ${index}: ${response.status}`);
            }

            return response.json('data.0.id');
        });

    // Kunci seed stabil per RUN_ID: menjalankan ulang pada database yang sama memakai kembali
    // record yang sama, bukan menumbuhkan data seed.
    const groupIds = tahap('group-aset', semua((tenant, index) => ['POST', ASET('group-aset'), JSON.stringify({ nama: `Group seed ${index}`, keterangan: 'seed load test' }), params(tenant, `seed-${RUN_ID}-group-${index}`)]));
    const jenisIds = tahap('jenis-aset', semua((tenant, index) => ['POST', ASET('jenis-aset'), JSON.stringify({ nama: `Jenis seed ${index}` }), params(tenant, `seed-${RUN_ID}-jenis-${index}`)]));
    const pabrikanIds = tahap('pabrikan-aset', semua((tenant, index) => ['POST', ASET('pabrikan-aset'), JSON.stringify({ nama: `Pabrikan seed ${index}` }), params(tenant, `seed-${RUN_ID}-pabrikan-${index}`)]));
    const modelIds = tahap('model-aset', semua((tenant, index) => ['POST', ASET('model-aset'), JSON.stringify({ nama: `Model seed ${index}`, pabrikan_aset_id: pabrikanIds[index], jenis_aset_id: jenisIds[index] }), params(tenant, `seed-${RUN_ID}-model-${index}`)]));
    const profilIds = tahap('profil-penyusutan', semua((tenant, index) => ['POST', ASET('profil-penyusutan'), JSON.stringify({ nama: `Profil seed ${index}`, method: 'straight_line', frequency: 'monthly', year_basis: 'calendar', useful_life_periods: 60 }), params(tenant, `seed-${RUN_ID}-profil-${index}`)]));
    const bukuIds = tahap('buku-penyusutan', semua((tenant, index) => ['POST', ASET('buku-penyusutan'), JSON.stringify({ nama: `Buku seed ${index}`, posting_layer: 'current', depreciation_profile_id: profilIds[index] }), params(tenant, `seed-${RUN_ID}-buku-${index}`)]));
    const tipeAtributIds = tahap('tipe-atribut', semua((tenant, index) => ['POST', ASET('tipe-atribut'), JSON.stringify({ nama: `Warna load test ${index}`, data_type: 'string' }), params(tenant, `seed-${RUN_ID}-tipe-atribut-${index}`)]));

    tahap('values tipe-atribut', semua((tenant, index) => ['PUT', `${ASET('tipe-atribut')}/${tipeAtributIds[index]}/nilai`, JSON.stringify({ rows: [{ nilai: 'A', urutan: 0 }, { nilai: 'B', urutan: 1 }] }), params(tenant)]));
    tahap('atribut jenis-aset', semua((tenant, index) => ['PUT', `${ASET('jenis-aset')}/${jenisIds[index]}/atribut`, JSON.stringify({ rows: [{ tipe_atribut_id: tipeAtributIds[index], urutan: 0 }] }), params(tenant)]));
    // Matriks group x buku wajib terisi sebelum aset dapat ditempatkan; tanpa baris ini
    // seluruh mutasi dijawab 422 dan skenario diam-diam berhenti menguji penempatan.
    tahap(
        'matriks group x buku',
        semua((tenant, index) => [
            'PUT',
            `${ASET('group-aset')}/${groupIds[index]}/buku-penyusutan`,
            JSON.stringify({ rows: [{ buku_id: bukuIds[index], depreciation_profile_id: profilIds[index], useful_life_periods: 60, convention: 'full_month', depreciate: true }] }),
            params(tenant),
        ]),
    );

    const assetIds = tahap(
        'aset',
        semua((tenant, index) => [
            'POST',
            ASET('aset'),
            JSON.stringify({
                nama: `Aset seed ${index}`,
                legal_entity_id: tenant.legalEntityId,
                usage_org_unit_id: tenant.orgUnitId,
                group_aset_id: groupIds[index],
                jenis_aset_id: jenisIds[index],
                acquired_on: '2026-01-01',
                acquisition_value: 1000000,
                currency_code: 'IDR',
                atribut: [{ tipe_atribut_id: tipeAtributIds[index], nilai: 'A' }],
            }),
            params(tenant, `seed-${RUN_ID}-aset-${index}`),
        ]),
    );

    const lengkap = tenants.map((tenant, index) => ({
        ...tenant,
        satuanId: satuan[index],
        groupAsetId: groupIds[index],
        jenisAsetId: jenisIds[index],
        pabrikanAsetId: pabrikanIds[index],
        modelAsetId: modelIds[index],
        profilPenyusutanId: profilIds[index],
        bukuPenyusutanId: bukuIds[index],
        tipeAtributId: tipeAtributIds[index],
        assetId: assetIds[index],
    }));

    console.log(`setup: ${lengkap.length} tenant siap + 1 tenant berhak sempit`);

    return { tenants: lengkap, sempit };
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

function post(tenant, resource, body, kunci, extra) {
    return http.post(ASET(resource), JSON.stringify(body), paramsUntuk(tenant, { tags: { op: 'create', resource }, ...extra }, { 'Idempotency-Key': kunci }));
}

function listMaster(tenant) {
    const resources = Object.keys(CHAINED).concat(STANDALONE);
    const resource = resources[Math.floor(Math.random() * resources.length)];
    const chained = CHAINED[resource];
    const query = chained ? `?per_page=20&${chained.parentField}=${tenant[chained.seed]}` : '?per_page=20';

    const response = http.get(`${ASET(resource)}${query}`, paramsUntuk(tenant, { tags: { op: 'list', resource } }));
    record(response, readLatency, 200, 'list 200');

    if (response.status === 200) {
        const rows = response.json('data') || [];

        for (const row of rows) {
            // Prefix kode berasal dari reference Number Sequence milik master ini. Prefix
            // asing di sini berarti daftar memuat baris master lain — atau nomor terbit dari
            // reference yang salah.
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
    const response = http.get(`${ASET('model-aset')}/${tenant.modelAsetId}`, paramsUntuk(tenant, { tags: { op: 'show', resource: 'model-aset' } }));
    record(response, readLatency, 200, 'show 200');

    // Model membawa dua induk sekaligus; keduanya harus tersaji utuh dan tidak tertukar.
    if (response.status === 200 && (response.json('data.pabrikan_aset.id') !== tenant.pabrikanAsetId || response.json('data.jenis_aset.id') !== tenant.jenisAsetId)) {
        violation('wrong_parent_summary');
    }
}

function createMaster(tenant) {
    const pool = Object.keys(CHAINED).concat(STANDALONE);
    const resource = pool[Math.floor(Math.random() * pool.length)];
    const chained = CHAINED[resource];
    const kunci = `vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}-${resource}`;
    const body = { nama: `${resource} ${kunci}`, keterangan: 'load test' };

    if (chained) {
        body[chained.parentField] = tenant[chained.seed];
    }

    const response = post(tenant, resource, body, kunci);
    record(response, writeLatency, 201, 'create 201');

    if (response.status === 201 && !String(response.json('data.kode')).startsWith(KODE_PREFIX[resource])) {
        violation('wrong_sequence_prefix_on_create', { resource });
    }
}

/**
 * Mengganti seluruh matriks group x buku. Dijalankan berbarengan pada pemilik yang sama untuk
 * membuktikan jalur ganti-seluruh-himpunan tidak menabrak unique index saat dua penyuntingan
 * beradu.
 */
function replaceMatrix(tenant) {
    const response = http.put(
        `${ASET('group-aset')}/${tenant.groupAsetId}/buku-penyusutan`,
        JSON.stringify({
            rows: [{
                buku_id: tenant.bukuPenyusutanId,
                depreciation_profile_id: tenant.profilPenyusutanId,
                useful_life_periods: 12 + (exec.vu.idInTest % 48),
                convention: 'full_month',
                depreciate: true,
            }],
        }),
        paramsUntuk(tenant, { tags: { op: 'replace_link', resource: 'group-buku-penyusutan' } }),
    );
    record(response, writeLatency, 200, 'replace matrix 200');

    if (response.status >= 500 && response.status !== 502 && response.status !== 504) {
        violation('link_replace_conflict', { status: response.status });
    }
}

function updateMaster(tenant) {
    const response = http.patch(
        `${ASET('model-aset')}/${tenant.modelAsetId}`,
        JSON.stringify({ keterangan: `disentuh vu${exec.vu.idInTest}` }),
        paramsUntuk(tenant, { tags: { op: 'update', resource: 'model-aset' } }),
    );
    record(response, writeLatency, 200, 'update 200');
}

/** Dua permintaan identik berbarengan harus menghasilkan tepat satu record. */
function idempotencyRace(tenant) {
    const kunci = `race-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
    const body = JSON.stringify({ nama: `race ${kunci}` });
    const params = paramsUntuk(tenant, { tags: { op: 'race', resource: 'group-aset' } }, { 'Idempotency-Key': kunci });

    const [first, second] = http.batch([
        ['POST', ASET('group-aset'), body, params],
        ['POST', ASET('group-aset'), body, params],
    ]);

    [first, second].forEach((response) => writeLatency.add(response.timings.duration));
    const ok = (response) => response.status >= 200 && response.status < 300;

    if (!ok(first) || !ok(second)) {
        recordFailure(first, 'race');
        recordFailure(second, 'race');

        return;
    }

    if (first.json('data.id') !== second.json('data.id')) {
        violation('idempotency_produced_two_records');
    }

    if (first.status === 200 || second.status === 200) {
        idempotencyReplays.add(1);
    }
}

/** Sesi tenant A tidak boleh menyentuh data tenant B, baca maupun tulis. */
function crossTenantProbe(tenant, victim) {
    crossTenantProbes.add(1);

    // SELFTEST membaca record milik sendiri, yang wajib 200 dan wajib dihitung sebagai
    // pelanggaran oleh baris di bawahnya.
    const sasaran = SELFTEST ? tenant : victim;
    const read = http.get(`${ASET('model-aset')}/${sasaran.modelAsetId}`, paramsUntuk(tenant, {
        tags: { op: 'probe_read', resource: 'model-aset' },
        responseCallback: http.expectedStatuses(404),
    }));

    if (read.status === 200) {
        violation('cross_tenant_read');
    }

    const write = post(
        tenant,
        'model-aset',
        { nama: 'model curian', pabrikan_aset_id: victim.pabrikanAsetId },
        `steal-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`,
        { tags: { op: 'probe_write', resource: 'model-aset' }, responseCallback: http.expectedStatuses(422) },
    );

    if (write.status === 201) {
        violation('cross_tenant_parent_accepted');
    }

    const plan = post(
        tenant,
        'perencanaan-aset',
        planningBody(tenant, victim.jenisAsetId),
        `steal-plan-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`,
        { tags: { op: 'probe_write', resource: 'perencanaan-aset' }, responseCallback: http.expectedStatuses(422) },
    );

    if (plan.status === 201) {
        violation('cross_tenant_plan_type_accepted');
    }
}

/** Hak pada satu master tidak boleh merembet ke master lain, termasuk saat sistem jenuh. */
function permissionScopeProbe(sempit) {
    scopeProbes.add(1);

    const boleh = http.get(`${ASET('group-aset')}?per_page=1`, paramsUntuk(sempit, { tags: { op: 'probe_scope_allowed', resource: 'group-aset' } }));

    // Hanya penolakan yang benar-benar dijawab server yang dihitung. Status 0 berarti klien
    // menyerah menunggu — itu kapasitas, bukan hak yang dicabut, dan menghitungnya sebagai
    // pelanggaran membuat gate kebenaran ikut memerah setiap kali mesin kehabisan napas.
    if (boleh.status === 401 || boleh.status === 403 || boleh.status === 404) {
        violation('permission_scope_denied_wrongly', { status: boleh.status });
    }

    const ditolak = http.get(`${ASET('model-aset')}?per_page=1`, paramsUntuk(sempit, {
        tags: { op: 'probe_scope_denied', resource: 'model-aset' },
        responseCallback: http.expectedStatuses(403),
    }));

    if (ditolak.status === 200) {
        violation('permission_scope_escalation');
    }
}

function planningBody(tenant, jenisAsetId = tenant.jenisAsetId) {
    return {
        legal_entity_id: tenant.legalEntityId,
        planning_org_unit_id: tenant.orgUnitId,
        planned_on: '2026-01-01',
        planning_year: 2026,
        planning_type: 'regular',
        funding_source: 'load test',
        description: 'load test planning',
        details: [{ jenis_aset_id: jenisAsetId, satuan_id: tenant.satuanId, quantity: 1, requested_specification: 'RAM 16 GB', estimated_unit_price: 1000000 }],
    };
}

function lifecycleTransaction(tenant) {
    const kunci = `plan-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
    const response = post(tenant, 'perencanaan-aset', planningBody(tenant), kunci);
    record(response, writeLatency, 201, 'planning create 201');

    if (response.status === 201) {
        if (!String(response.json('data.kode')).startsWith('PLNA')) {
            violation('wrong_sequence_prefix_on_plan');
        }

        // Perencanaan membawa tenant_id di payload; masters tidak. Selama ia ada, ia dipakai.
        if (String(response.json('data.tenant_id')) !== String(tenant.id)) {
            violation('plan_written_to_other_tenant');
        }
    }
}

function planningIdempotencyRace(tenant) {
    const kunci = `plan-race-vu${exec.vu.idInTest}-it${exec.scenario.iterationInTest}`;
    const body = JSON.stringify(planningBody(tenant));
    const params = paramsUntuk(tenant, { tags: { op: 'race', resource: 'perencanaan-aset' } }, { 'Idempotency-Key': kunci });
    const [first, second] = http.batch([
        ['POST', ASET('perencanaan-aset'), body, params],
        ['POST', ASET('perencanaan-aset'), body, params],
    ]);
    [first, second].forEach((response) => writeLatency.add(response.timings.duration));
    const ok = (response) => response.status >= 200 && response.status < 300;

    if (!ok(first) || !ok(second)) {
        recordFailure(first, 'plan-race');
        recordFailure(second, 'plan-race');

        return;
    }

    if (first.json('data.id') !== second.json('data.id')) {
        violation('plan_idempotency_produced_two_records');
    }
}

function mutateAsset(tenant) {
    const response = http.post(
        `${ASET('aset')}/${tenant.assetId}/penempatan`,
        JSON.stringify({ effective_on: '2026-01-02', reason: 'load test mutasi', usage_org_unit_id: tenant.orgUnitId }),
        paramsUntuk(tenant, { tags: { op: 'mutate', resource: 'aset' } }),
    );
    record(response, writeLatency, 200, 'mutate 200');
}

/**
 * Values dipersempit ke A tepat saat aset mencoba menyimpan B. Hasil yang sah hanya: Values
 * menang lalu aset ditolak, atau aset menang lalu Values ditolak. Keduanya 200 berarti row
 * lock gagal dan database dapat menyimpan B di luar Values aktif.
 */
function attributeConstraintRace(tenant) {
    const valuesBody = JSON.stringify({ rows: [{ nilai: 'A', urutan: 0 }] });
    const assetBody = JSON.stringify({ atribut: [{ tipe_atribut_id: tenant.tipeAtributId, nilai: 'B' }] });
    const [values, asset] = http.batch([
        ['PUT', `${ASET('tipe-atribut')}/${tenant.tipeAtributId}/nilai`, valuesBody, paramsUntuk(tenant, {
            tags: { op: 'attribute_race_values', resource: 'tipe-atribut' },
            responseCallback: http.expectedStatuses(200, 409),
        })],
        ['PATCH', `${ASET('aset')}/${tenant.assetId}`, assetBody, paramsUntuk(tenant, {
            tags: { op: 'attribute_race_asset', resource: 'aset' },
            responseCallback: http.expectedStatuses(200, 422),
        })],
    ]);

    [values, asset].forEach((response) => {
        writeLatency.add(response.timings.duration);
        recordFailure(response, 'attribute-race');
    });
    const tidakTersedia = [values, asset].some((response) => response.status === 0 || response.status === 502 || response.status === 504);
    const sah = (values.status === 200 && asset.status === 422) || (values.status === 409 && asset.status === 200);

    if (!tidakTersedia && !sah) {
        violation('attribute_values_race', { values: values.status, asset: asset.status });
    }
}

// ---------------------------------------------------------------- vu loop

export default function (data) {
    const tenants = data.tenants;
    // Satu VU, satu tenant: sesinya milik satu pengguna, dan cookie-nya dibangun sekali.
    const tenant = tenantVu(tenants, exec.vu.idInTest);

    if (PROFILE === 'attribute-race') {
        attributeConstraintRace(tenant);

        return;
    }

    const roll = Math.random();

    if (roll < 0.35) {
        listMaster(tenant);
    } else if (roll < 0.5) {
        showMaster(tenant);
    } else if (roll < 0.68) {
        createMaster(tenant);
    } else if (roll < 0.74) {
        updateMaster(tenant);
    } else if (roll < 0.77) {
        replaceMatrix(tenant);
    } else if (roll < 0.84) {
        lifecycleTransaction(tenant);
    } else if (roll < 0.89) {
        mutateAsset(tenant);
    } else if (roll < 0.92) {
        idempotencyRace(tenant);
    } else if (roll < 0.94) {
        planningIdempotencyRace(tenant);
    } else if (roll < 0.96) {
        attributeConstraintRace(tenant);
    } else if (roll < 0.99) {
        const victim = tenants[(tenant.index + 1) % tenants.length];
        crossTenantProbe(tenant, victim);
    } else {
        // Pada SELFTEST probe eskalasi memakai sesi yang memang berhak penuh; `model-aset`
        // dijawab 200, dan itu harus terbaca sebagai eskalasi.
        permissionScopeProbe(SELFTEST ? tenant : tenantSempit(data.sempit));
    }
}

let sempitCache = null;

function tenantSempit(sempit) {
    if (sempitCache === null) {
        sempitCache = bangunJar(sempit);
    }

    return sempitCache;
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];

        return value === undefined ? null : Number(value.toFixed(2));
    };

    const summary = {
        skenario: 'master-data',
        profile: PROFILE,
        run_id: RUN_ID,
        selftest: SELFTEST,
        vus_configured: PROFILE === 'latency' ? Number(__ENV.LATENCY_VUS || 16) : VUS,
        tenants: TENANT_COUNT,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        iterations: data.metrics.iterations?.values?.count ?? 0,
        requests: data.metrics.http_reqs?.values?.count ?? 0,
        throughput_rps: metric('http_reqs', 'rate'),
        kebenaran: {
            correctness_violations: data.metrics.correctness_violations?.values?.count ?? 0,
            server_errors: data.metrics.server_errors?.values?.count ?? 0,
            cross_tenant_probes: data.metrics.cross_tenant_probes?.values?.count ?? 0,
            permission_scope_probes: data.metrics.permission_scope_probes?.values?.count ?? 0,
            idempotency_replays: data.metrics.idempotency_replays?.values?.count ?? 0,
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
        stdout: `\n===== RINGKASAN LOAD TEST master-data (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-master-data-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
