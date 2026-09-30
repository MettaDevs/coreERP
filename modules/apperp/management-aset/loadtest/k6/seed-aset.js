// Data awal aset untuk skenario yang butuh aset tetapi tidak menguji penerimaannya.
//
// Aset tidak lagi dibuat langsung: `POST /aset` dipensiunkan 18 September 2026, dan satu-satunya
// jalan lahirnya aset adalah penerimaan yang diselesaikan. Dua skenario (`master-data.js`,
// `work-order.js`) berhenti di `setup()` sejak itu tanpa ada yang tahu. Helper ini jalan yang
// sama untuk keduanya: penerimaan saldo awal satu unit, tanpa vendor dan PPN, lalu diselesaikan.

import { fail } from 'k6';
import http from 'k6/http';
import { BASE, paramsUntuk, urlModule } from '../lib.js';

const ASET = (path) => urlModule('management-aset', path);

/**
 * Kode diketik untuk group aset dan buku penyusutan (K-24): huruf besar, angka, dan tanda hubung,
 * paling panjang 30 karakter. Kode unik per tenant, termasuk kode record yang sudah diarsipkan.
 */
export function kodeManual(...bagian) {
    return bagian
        .join('-')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 30)
        .replace(/-+$/, '');
}

/**
 * Penanda pendek untuk satu run, dipakai di dalam kode diketik yang panjangnya dibatasi. Run berbeda pada
 * fixture yang sama berbagi tenant, jadi kode yang hanya disusun dari nomor VU dan iterasi bertabrakan.
 */
export function tandaRun(runId) {
    let hash = 0;

    for (const huruf of String(runId)) {
        hash = (hash * 31 + huruf.charCodeAt(0)) >>> 0;
    }

    return hash.toString(36).toUpperCase();
}

function wajib(label, responses, statusSah) {
    responses.forEach((response, index) => {
        if (!statusSah.includes(response.status)) {
            fail(`setup ${label} gagal pada tenant ${index}: ${response.status} ${String(response.body).slice(0, 400)}`);
        }
    });

    return responses;
}

/**
 * Satu aset per tenant, lahir dari penerimaan saldo awal yang diselesaikan.
 *
 * Entitas legal tiap tenant diberi tanggal cutover 1 Januari 2026 lebih dulu. Group yang dipakai wajib sudah punya matriks group x buku dengan buku yang di-post (K-26); tanpa itu
 * penyelesaian dijawab 422. Kunci idempotency diikat ke `kunci`, jadi menjalankan ulang pada fixture
 * yang sama memakai penerimaan yang sama, dan penerimaan yang sudah selesai tidak diselesaikan lagi.
 *
 * @param {Array<object>} jars tenant yang sudah membawa cookie jar (`bangunJar`)
 * @param {{groupIds: string[], jenisIds: string[]}} ids
 * @param {(index: number) => string} kunci
 * @param {{lokasiIds?: string[], nama?: (index: number) => string, jumlah?: number, semua?: boolean}} [opsi]
 *   Lokasi penerimaan per tenant, nama aset, dan jumlah unit pada satu baris. `semua` memulangkan
 *   seluruh id aset per tenant, bukan hanya yang pertama; dipakai skenario monitoring.
 * @returns {string[]|string[][]} id aset per tenant
 */
export function lahirkanAset(jars, ids, kunci, opsi = {}) {
    // Jurnal saldo awal bertanggal cutover, jadi entitas legal wajib punya tanggalnya. Pengiriman
    // posting sendiri dibiarkan mati: skenario pemanggilnya tidak mengukur feed finance.
    // Setelan membawa versi barisnya (area 3); 0 selama setelannya belum pernah disimpan.
    const versiSetelan = http
        .batch(jars.map((tenant) => ['GET', `${BASE}/api/v1/organizations/${tenant.legalEntityId}/finance-posting`, null, paramsUntuk(tenant)]))
        .map((response) => response.json('data.version'));
    wajib(
        'tanggal cutover',
        http.batch(jars.map((tenant, index) => ['PUT', `${BASE}/api/v1/organizations/${tenant.legalEntityId}/finance-posting`, JSON.stringify({ enabled: false, cutover_date: '2026-01-01', version: versiSetelan[index] }), paramsUntuk(tenant)])),
        [200],
    );

    const draf = wajib(
        'draf penerimaan saldo awal',
        http.batch(
            jars.map((tenant, index) => [
                'POST',
                ASET('penerimaan-aset'),
                JSON.stringify({
                    legal_entity_id: tenant.legalEntityId,
                    responsible_org_unit_id: tenant.orgUnitId,
                    cara_perolehan: 'saldo_awal',
                    tanggal: '2025-06-01',
                    currency_code: 'IDR',
                    lokasi_aset_id: opsi.lokasiIds ? opsi.lokasiIds[index] : null,
                    details: [{
                        nama: opsi.nama ? opsi.nama(index) : `Aset uji beban ${index}`,
                        group_aset_id: ids.groupIds[index],
                        jenis_aset_id: ids.jenisIds[index],
                        jumlah: opsi.jumlah || 1,
                        nilai_per_unit: '1000000',
                        ppn_per_unit: '0',
                        akumulasi_per_unit: '0',
                        periode_berjalan: 0,
                    }],
                }),
                paramsUntuk(tenant, {}, { 'Idempotency-Key': kunci(index) }),
            ]),
        ),
        [200, 201],
    );

    const perluSelesai = draf.map((response) => response.json('data.status') !== 'selesai');
    const selesai = http.batch(
        jars
            .map((tenant, index) => [tenant, index])
            .filter(([, index]) => perluSelesai[index])
            .map(([tenant, index]) => [
                'POST',
                `${ASET('penerimaan-aset')}/${draf[index].json('data.id')}/selesaikan`,
                JSON.stringify({ version: draf[index].json('data.version') }),
                paramsUntuk(tenant),
            ]),
    );
    wajib('penyelesaian penerimaan saldo awal', selesai, [200]);

    const aset = wajib(
        'aset hasil penerimaan',
        http.batch(jars.map((tenant, index) => ['GET', `${ASET('penerimaan-aset')}/${draf[index].json('data.id')}/aset`, null, paramsUntuk(tenant)])),
        [200],
    );

    return aset.map((response, index) => {
        if (opsi.semua) {
            const semua = (response.json('data') || []).map((baris) => String(baris.id));

            if (semua.length !== (opsi.jumlah || 1)) {
                fail(`setup aset tenant ${index}: ${semua.length} aset, diharapkan ${opsi.jumlah || 1}`);
            }

            return semua;
        }

        const id = response.json('data.0.id');

        if (!id) {
            fail(`setup aset tenant ${index}: penerimaan selesai tanpa aset ${String(response.body).slice(0, 300)}`);
        }

        return String(id);
    });
}
