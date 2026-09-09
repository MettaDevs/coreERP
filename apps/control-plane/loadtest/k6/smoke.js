// Skrip asap: membuktikan penyiapan tenant, sesi bersama, dan rute module bekerja pada
// stack gabungan sebelum skenario besar dijalankan. Bukan gate; alat bantu diagnosis.
import http from 'k6/http';
import { siapkanTenant, sempitkanTenant, paramsUntuk, bangunJar, urlModule } from './lib.js';

export const options = { scenarios: { asap: { executor: 'per-vu-iterations', vus: 1, iterations: 1 } }, setupTimeout: '10m' };

const ASET = (path) => urlModule('management-aset', path);

export function setup() {
    const tenants = siapkanTenant(Number(__ENV.TENANTS || 2), ['management-aset']);
    const sempit = sempitkanTenant(siapkanTenant(1, ['management-aset'], 'narrow')[0]);

    return { tenants, sempit };
}

function lapor(label, response) {
    console.log(`${label}: ${response.status} ${String(response.body).slice(0, 220)}`);

    return response;
}

export default function (data) {
    const a = bangunJar(data.tenants[0]);
    const b = bangunJar(data.tenants[1]);
    const sempit = bangunJar(data.sempit);
    const kunci = (nama) => ({ 'Idempotency-Key': `asap-${nama}-${Date.now()}` });

    lapor('health', http.get(ASET('health'), paramsUntuk(a)));

    const satuan = lapor('satuan', http.get(ASET('reference-data/units-of-measure'), paramsUntuk(a)));
    const satuanId = satuan.status === 200 ? satuan.json('data.0.id') : null;

    const group = lapor('group-aset', http.post(ASET('group-aset'), JSON.stringify({ nama: 'Group asap' }), paramsUntuk(a, {}, kunci('group'))));
    const jenis = lapor('jenis-aset', http.post(ASET('jenis-aset'), JSON.stringify({ nama: 'Jenis asap' }), paramsUntuk(a, {}, kunci('jenis'))));
    const pabrikan = lapor('pabrikan-aset', http.post(ASET('pabrikan-aset'), JSON.stringify({ nama: 'Pabrikan asap' }), paramsUntuk(a, {}, kunci('pabrikan'))));
    // Nilainya tidak dipakai — yang diuji bahwa pembuatannya berhasil, dan `lapor` yang
    // mencatatnya. Menyimpannya ke variabel hanya menyisakan nama yang tidak pernah dibaca.
    lapor(
        'model-aset',
        http.post(ASET('model-aset'), JSON.stringify({ nama: 'Model asap', pabrikan_aset_id: pabrikan.json('data.id'), jenis_aset_id: jenis.json('data.id') }), paramsUntuk(a, {}, kunci('model'))),
    );
    const profil = lapor('profil-penyusutan', http.post(ASET('profil-penyusutan'), JSON.stringify({ nama: 'Profil asap', method: 'straight_line', frequency: 'monthly', year_basis: 'calendar', useful_life_periods: 60 }), paramsUntuk(a, {}, kunci('profil'))));
    const buku = lapor('buku-penyusutan', http.post(ASET('buku-penyusutan'), JSON.stringify({ nama: 'Buku asap', posting_layer: 'current', depreciation_profile_id: profil.json('data.id') }), paramsUntuk(a, {}, kunci('buku'))));
    const tipeAtribut = lapor('tipe-atribut', http.post(ASET('tipe-atribut'), JSON.stringify({ nama: 'Warna asap', data_type: 'string' }), paramsUntuk(a, {}, kunci('tipe-atribut'))));
    lapor('nilai tipe-atribut', http.put(`${ASET('tipe-atribut')}/${tipeAtribut.json('data.id')}/nilai`, JSON.stringify({ rows: [{ nilai: 'A', urutan: 0 }, { nilai: 'B', urutan: 1 }] }), paramsUntuk(a)));
    lapor('atribut jenis-aset', http.put(`${ASET('jenis-aset')}/${jenis.json('data.id')}/atribut`, JSON.stringify({ rows: [{ tipe_atribut_id: tipeAtribut.json('data.id'), urutan: 0 }] }), paramsUntuk(a)));
    lapor(
        'matriks group x buku',
        http.put(`${ASET('group-aset')}/${group.json('data.id')}/buku-penyusutan`, JSON.stringify({ rows: [{ buku_id: buku.json('data.id'), depreciation_profile_id: profil.json('data.id'), useful_life_periods: 12, convention: 'full_month', depreciate: true }] }), paramsUntuk(a)),
    );

    const aset = lapor(
        'aset',
        http.post(
            ASET('aset'),
            JSON.stringify({
                nama: 'Aset asap',
                legal_entity_id: a.legalEntityId,
                usage_org_unit_id: a.orgUnitId,
                group_aset_id: group.json('data.id'),
                jenis_aset_id: jenis.json('data.id'),
                acquired_on: '2026-01-01',
                acquisition_value: 1000000,
                currency_code: 'IDR',
                atribut: [{ tipe_atribut_id: tipeAtribut.json('data.id'), nilai: 'A' }],
            }),
            paramsUntuk(a, {}, kunci('aset')),
        ),
    );

    if (aset.status === 201) {
        lapor('penempatan aset', http.post(`${ASET('aset')}/${aset.json('data.id')}/penempatan`, JSON.stringify({ effective_on: '2026-01-02', reason: 'asap', usage_org_unit_id: a.orgUnitId }), paramsUntuk(a)));
    }

    lapor(
        'perencanaan-aset',
        http.post(
            ASET('perencanaan-aset'),
            JSON.stringify({
                legal_entity_id: a.legalEntityId,
                planning_org_unit_id: a.orgUnitId,
                planned_on: '2026-01-01',
                planning_year: 2026,
                planning_type: 'regular',
                funding_source: 'asap',
                description: 'asap',
                details: [{ jenis_aset_id: jenis.json('data.id'), satuan_id: satuanId, quantity: 1, requested_specification: 'RAM 16 GB', estimated_unit_price: 1000000 }],
            }),
            paramsUntuk(a, {}, kunci('plan')),
        ),
    );

    const jobType = lapor('maintenance-job-types', http.post(ASET('maintenance-job-types'), JSON.stringify({ nama: 'Job asap' }), paramsUntuk(a, {}, kunci('job'))));

    if (jobType.status === 201) {
        lapor('kaitan job type x jenis aset', http.put(`${ASET('maintenance-job-types')}/${jobType.json('data.id')}/asset-types`, JSON.stringify({ jenis_aset_ids: [jenis.json('data.id')] }), paramsUntuk(a)));
        lapor('baca kaitan', http.get(`${ASET('maintenance-job-types')}/${jobType.json('data.id')}/asset-types`, paramsUntuk(a)));
    }

    const variabel = lapor('maintenance-checklist-variables', http.post(ASET('maintenance-checklist-variables'), JSON.stringify({ nama: 'Variabel asap' }), paramsUntuk(a, {}, kunci('var'))));

    if (variabel.status === 201) {
        lapor(
            'nilai variabel',
            http.put(`${ASET('maintenance-checklist-variables')}/${variabel.json('data.id')}/values`, JSON.stringify({ values: [{ line_number: 1, value: 'Baik', result_code: 'pass' }, { line_number: 2, value: 'Rusak', result_code: 'fail' }] }), paramsUntuk(a)),
        );
    }

    if (group.status === 201) {
        const lintas = http.get(`${ASET('group-aset')}/${group.json('data.id')}`, paramsUntuk(b));
        console.log(`baca lintas tenant (harus 404): ${lintas.status}`);
        const curi = http.post(ASET('model-aset'), JSON.stringify({ nama: 'model curian', pabrikan_aset_id: pabrikan.json('data.id') }), paramsUntuk(b, {}, kunci('curi')));
        console.log(`tulis induk lintas tenant (harus 422): ${curi.status} ${String(curi.body).slice(0, 160)}`);
    }

    const bolehkan = http.get(`${ASET('group-aset')}?per_page=1`, paramsUntuk(sempit));
    const tolak = http.get(`${ASET('model-aset')}?per_page=1`, paramsUntuk(sempit));
    console.log(`sempit: group-aset ${bolehkan.status} (harus 200), model-aset ${tolak.status} (harus 403)`);
}
