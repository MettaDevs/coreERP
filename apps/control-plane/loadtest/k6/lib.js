// Perkakas bersama seluruh skenario uji beban, sesudah module masuk ke runtime Core.
//
// ## Yang berubah, dan kenapa perkakasnya ikut berubah
//
// Waktu module masih app berkontainer, sebuah skenario membawa token konteks terbitan Core:
// JWT HS256 yang dicetak di luar k6, ditempel sebagai `Authorization: Bearer`, dan berlaku dua
// jam. Rute module sekarang berada di belakang `['web', 'auth']` — sesi Core, cookie, dan CSRF —
// karena konteksnya dibaca dari sesi, bukan dari token yang ditandatangani lalu diperiksa proses
// yang sama. Karena itu tidak ada lagi `mint-tenants.mjs`, tidak ada lagi kunci penandatangan,
// dan tidak ada lagi berkas fixture: tenant disiapkan lewat alur pendaftaran usaha yang sungguhan,
// sekali per run, di dalam `setup()`.
//
// ## Satu sesi dipakai banyak VU, dan itu disengaja
//
// Login berbagi limiter dengan pengguna sungguhan (lima percobaan per menit per email + IP), jadi
// seribu VU yang masing-masing login akan mengunci dirinya sendiri sebelum request pertama. Yang
// dilakukan di sini: sesi dibuka SEKALI per tenant pada `setup()`, cookienya diturunkan ke seluruh
// VU, dan tiap VU membangun ulang `CookieJar`-nya sendiri dari cookie itu.
//
// Itu bukan jalan pintas — justru itu yang ingin dibuktikan. Sesi disimpan di tabel `sessions`,
// jadi satu sesi yang dipakai empat instance sekaligus adalah persis keadaan yang gagal bila ada
// identitas, tenant, atau cache izin yang menempel pada memori satu proses.

import http from 'k6/http';
import { fail } from 'k6';

export const BASE = __ENV.BASE_URL || 'http://lb';
export const RUN_ID = __ENV.RUN_ID || 'r0';
// Identitas fixture dipisahkan dari RUN_ID supaya beberapa run dapat memakai kembali tenant yang
// sama. Pendaftaran yang menabrak email yang sudah ada dijawab 422, dan itu diperlakukan sebagai
// "sudah ada" — bukan kegagalan.
export const FIXTURE = __ENV.FIXTURE || 'f1';
export const PASSWORD = 'Loadtest-Owner-2026!';

const DUTY_SEMPIT = __ENV.NARROW_DUTY || 'management-aset.group-aset.manage';

function email(index, tag) {
    return `load-${FIXTURE}${tag}-${index}@example.test`;
}

function csrfDari(jar) {
    const cookies = jar.cookiesForURL(BASE);
    const nilai = cookies['XSRF-TOKEN']?.[0] ?? '';

    return decodeURIComponent(nilai);
}

/** Header JSON lengkap dengan token CSRF; sama bentuknya dengan yang dikirim layar Core. */
export function jsonHeaders(csrf, extra) {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrf,
        ...extra,
    };
}

/**
 * Parameter permintaan untuk satu tenant: jar-nya sendiri dan token CSRF-nya sendiri.
 * `tenant.jar` dibangun VU lewat `bangunJar`, bukan diturunkan dari setup — objek CookieJar
 * tidak dapat melewati batas setup -> VU, hanya nilainya yang bisa.
 */
export function paramsUntuk(tenant, extra, headerTambahan) {
    return {
        jar: tenant.jar,
        headers: jsonHeaders(tenant.csrf, headerTambahan),
        ...extra,
    };
}

/** Membangun kembali CookieJar sebuah tenant di dalam VU dari cookie hasil setup. */
export function bangunJar(tenant) {
    const jar = new http.CookieJar();
    for (const [nama, nilai] of Object.entries(tenant.cookies)) {
        jar.set(BASE, nama, nilai);
    }

    return { ...tenant, jar };
}

/**
 * Cache per VU: satu VU melayani satu tenant sepanjang run, jadi jar-nya dibangun sekali.
 * Peta ini hidup di lingkup modul, dan tiap VU punya salinan modulnya sendiri.
 */
const jarCache = new Map();

export function tenantVu(daftar, index) {
    const kunci = index % daftar.length;
    if (!jarCache.has(kunci)) {
        jarCache.set(kunci, bangunJar(daftar[kunci]));
    }

    return jarCache.get(kunci);
}

/** Alamat rute JSON sebuah module di dalam runtime Core. */
export function urlModule(moduleId, path) {
    return `${BASE}/api/modules/${moduleId}/v1/${path}`;
}

// ------------------------------------------------------------------ penyiapan tenant

function batchWajib(label, requests, statusSah) {
    const responses = http.batch(requests);
    responses.forEach((response, index) => {
        if (!statusSah.includes(response.status)) {
            fail(`setup ${label} gagal pada tenant ${index}: ${response.status} ${String(response.body).slice(0, 400)}`);
        }
    });

    return responses;
}

/**
 * Menyiapkan `jumlah` tenant lengkap dengan owner, entitlement module, satu legal entity,
 * dan satu operating unit. Tiap tahap dijalankan berbarengan untuk seluruh tenant supaya
 * setup tidak menjadi bagian terlama dari uji beban.
 *
 * @returns {Array<{index:number,id:string,email:string,cookies:Record<string,string>,csrf:string,legalEntityId:string,orgUnitId:string}>}
 */
export function siapkanTenant(jumlah, appIds, tag = '') {
    const jars = Array.from({ length: jumlah }, () => new http.CookieJar());
    const indeks = Array.from({ length: jumlah }, (_, index) => index);

    // 1. Cookie CSRF untuk form registrasi.
    batchWajib(
        'form registrasi',
        indeks.map((index) => ['GET', `${BASE}/register`, null, { jar: jars[index] }]),
        [200],
    );

    // 2. Pendaftaran usaha. 422 berarti fixture ini sudah pernah dibuat; itu sah dan
    //    membuat run berikutnya memakai kembali tenant yang sama.
    batchWajib(
        'pendaftaran usaha',
        indeks.map((index) => [
            'POST',
            `${BASE}/api/v1/business-registrations`,
            JSON.stringify({
                name: `Owner ${FIXTURE}${tag}-${index}`,
                business_name: `Bisnis ${FIXTURE}${tag}-${index}`,
                app_ids: appIds,
                email: email(index, tag),
                password: PASSWORD,
                password_confirmation: PASSWORD,
            }),
            { jar: jars[index], headers: jsonHeaders(csrfDari(jars[index])) },
        ]),
        [201, 422],
    );

    // 3. Cookie CSRF untuk form login, lalu login.
    batchWajib(
        'form login',
        indeks.map((index) => ['GET', `${BASE}/login`, null, { jar: jars[index] }]),
        [200],
    );
    batchWajib(
        'login',
        indeks.map((index) => [
            'POST',
            `${BASE}/login`,
            JSON.stringify({ email: email(index, tag), password: PASSWORD }),
            { jar: jars[index], headers: jsonHeaders(csrfDari(jars[index])) },
        ]),
        [200],
    );

    // 4. Organisasi. Registrasi tidak membuat satu pun, sedangkan konteks module memerlukan
    //    legal entity dan operating unit aktif; keduanya terpilih otomatis selama tenant hanya
    //    punya satu.
    const daftarOrganisasi = batchWajib(
        'daftar organisasi',
        indeks.map((index) => ['GET', `${BASE}/api/v1/organizations`, null, { jar: jars[index], headers: jsonHeaders(csrfDari(jars[index])) }]),
        [200],
    );
    const organisasi = daftarOrganisasi.map((response) => response.json('data') || []);
    const cari = (index, classification) => organisasi[index].find((row) => row.classification === classification);

    const perluLegal = indeks.filter((index) => !cari(index, 'legal_entity'));
    if (perluLegal.length > 0) {
        const dibuat = batchWajib(
            'legal entity',
            perluLegal.map((index) => [
                'POST',
                `${BASE}/api/v1/organizations`,
                JSON.stringify({ classification: 'legal_entity', name: `PT Beban ${FIXTURE}${tag}-${index}`, company_code: `LT${tag}${index}`, country_code: 'ID' }),
                { jar: jars[index], headers: jsonHeaders(csrfDari(jars[index])) },
            ]),
            [201],
        );
        perluLegal.forEach((index, urutan) => organisasi[index].push(dibuat[urutan].json('data')));
    }

    const perluUnit = indeks.filter((index) => !cari(index, 'operating_unit'));
    if (perluUnit.length > 0) {
        const dibuat = batchWajib(
            'operating unit',
            perluUnit.map((index) => [
                'POST',
                `${BASE}/api/v1/organizations`,
                JSON.stringify({ classification: 'operating_unit', name: `Unit Beban ${FIXTURE}${tag}-${index}`, operating_unit_type: OPERATING_UNIT_TYPE }),
                { jar: jars[index], headers: jsonHeaders(csrfDari(jars[index])) },
            ]),
            [201],
        );
        perluUnit.forEach((index, urutan) => organisasi[index].push(dibuat[urutan].json('data')));
    }

    return indeks.map((index) => {
        const legal = cari(index, 'legal_entity');
        const unit = cari(index, 'operating_unit');
        if (!legal || !unit) {
            fail(`setup organisasi tenant ${index} tidak lengkap`);
        }

        return {
            index,
            id: String(legal.tenant_id),
            email: email(index, tag),
            cookies: kumpulkanCookie(jars[index]),
            csrf: csrfDari(jars[index]),
            legalEntityId: String(legal.id),
            orgUnitId: String(unit.id),
        };
    });
}

const OPERATING_UNIT_TYPE = __ENV.OPERATING_UNIT_TYPE || 'department';

function kumpulkanCookie(jar) {
    const keluar = {};
    for (const [nama, nilai] of Object.entries(jar.cookiesForURL(BASE))) {
        keluar[nama] = nilai[0];
    }

    return keluar;
}

/**
 * Tenant dengan hak sengaja dipersempit: role Owner-nya disunting sampai hanya menyisakan
 * satu duty. Dipakai probe eskalasi hak — pemegangnya boleh membaca satu master dan wajib
 * ditolak pada master sebelahnya, juga ketika sistem sedang jenuh.
 */
export function sempitkanTenant(tenant, dutyCode = DUTY_SEMPIT) {
    const jar = new http.CookieJar();
    for (const [nama, nilai] of Object.entries(tenant.cookies)) {
        jar.set(BASE, nama, nilai);
    }
    const params = { jar, headers: jsonHeaders(tenant.csrf) };

    const daftar = http.get(`${BASE}/api/v1/roles`, params);
    if (daftar.status !== 200) {
        fail(`setup role tenant sempit gagal: ${daftar.status} ${String(daftar.body).slice(0, 300)}`);
    }
    const owner = (daftar.json('data') || []).find((role) => role.name === 'Owner');
    if (!owner) {
        fail('setup tenant sempit: role Owner tidak ditemukan');
    }

    const disunting = http.put(
        `${BASE}/api/v1/roles/${owner.id}`,
        JSON.stringify({ name: 'Owner', duty_codes: [dutyCode] }),
        params,
    );
    if (disunting.status !== 200) {
        fail(`setup penyempitan role gagal: ${disunting.status} ${String(disunting.body).slice(0, 300)}`);
    }

    return tenant;
}
