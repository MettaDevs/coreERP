// Menyiapkan tenant beserta token konteksnya untuk load test.
//
// Token dibuat di luar k6 supaya skrip k6 tidak bergantung pada modul crypto yang
// kontraknya berubah antar versi. Bentuk tokennya identik dengan terbitan Web Shell:
// HS256, iss coreerp, aud app id, tenant_id ULID, dan daftar permission efektif.
//
// TTL sengaja dipanjangkan agar tidak kedaluwarsa di tengah run. Load test ini tidak
// menguji masa hidup token; itu sudah diuji pada test feature.

import { createHmac, randomBytes } from 'node:crypto';
import { writeFileSync } from 'node:fs';

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const APP_ID = process.env.APP_ID ?? 'management-aset';
const SIGNING_KEY = process.env.SIGNING_KEY ?? 'loadtest-context-signing-key-32b!!';
const TENANT_COUNT = Number(process.env.TENANT_COUNT ?? 128);
const TTL_SECONDS = Number(process.env.TTL_SECONDS ?? 7200);
const OUTPUT = process.env.OUTPUT ?? 'k6/tenants.json';

const MASTERS = [
    'group-aset',
    'jenis-aset',
    'model-aset',
    'kondisi-aset',
    'pabrikan-aset',
    'item-checklist-maintenance',
    'analisa-maintenance',
    'tipe-lokasi-aset',
    'buku-penyusutan',
    'profil-penyusutan',
];
const ACTIONS = ['read', 'create', 'update', 'archive'];

function encodeCrockford(bytes, chars) {
    // Base32 Crockford, most-significant bit first, dipotong ke jumlah karakter diminta.
    let bits = 0;
    let value = 0;
    let out = '';
    for (const byte of bytes) {
        value = (value << 8) | byte;
        bits += 8;
        while (bits >= 5) {
            out += CROCKFORD[(value >>> (bits - 5)) & 31];
            bits -= 5;
        }
    }
    if (bits > 0) {
        out += CROCKFORD[(value << (5 - bits)) & 31];
    }

    return out.slice(0, chars);
}

function ulid() {
    const now = Date.now();
    const timeBytes = Buffer.alloc(6);
    timeBytes.writeUIntBE(now, 0, 6);

    return encodeCrockford(timeBytes, 10) + encodeCrockford(randomBytes(10), 16);
}

const b64url = (value) => Buffer.from(value).toString('base64url');

function contextToken(tenantId, permissions, legalEntityId, orgUnitId) {
    const issuedAt = Math.floor(Date.now() / 1000);
    const header = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
    const payload = b64url(
        JSON.stringify({
            iss: 'coreerp',
            aud: APP_ID,
            tenant_id: tenantId,
            sub: `loadtest-${tenantId}`,
            legal_entity_id: legalEntityId,
            org_unit_id: orgUnitId,
            data_policies: {
                'management-aset.asset-responsibility': {
                    all: false,
                    scope_grants: [{ legal_entity_id: legalEntityId, operating_unit_ids: [orgUnitId] }],
                },
            },
            permissions,
            iat: issuedAt,
            exp: issuedAt + TTL_SECONDS,
        }),
    );
    const signature = createHmac('sha256', SIGNING_KEY).update(`${header}.${payload}`).digest('base64url');

    return `${header}.${payload}.${signature}`;
}

const allPermissions = MASTERS.flatMap((master) => ACTIONS.map((action) => `${APP_ID}.${master}.${action}`));
const transactionPermissions = [
    `${APP_ID}.aset.read`, `${APP_ID}.aset.create`, `${APP_ID}.aset.mutate`, `${APP_ID}.mutasi-aset.read`,
    `${APP_ID}.penyusutan.read`, `${APP_ID}.penyusutan.create`, `${APP_ID}.penyusutan.finalize`, `${APP_ID}.penyusutan.correct`,
    ...['perencanaan-aset', 'permintaan-pembelian-aset', 'pemeliharaan-aset', 'penjualan-aset', 'pemusnahan-aset'].flatMap((resource) => [`${APP_ID}.${resource}.read`, `${APP_ID}.${resource}.create`]),
    `${APP_ID}.perencanaan-aset.update`, `${APP_ID}.perencanaan-aset.archive`,
];

const tenants = Array.from({ length: TENANT_COUNT }, () => {
    const id = ulid();

    const legalEntityId = ulid();
    const orgUnitId = ulid();
    return { id, legalEntityId, orgUnitId, satuanId: ulid(), token: contextToken(id, [...allPermissions, ...transactionPermissions], legalEntityId, orgUnitId) };
});

// Satu tenant tambahan yang hanya boleh melihat group aset. Dipakai untuk membuktikan
// batas hak akses per master tetap tegak saat sistem sedang jenuh.
const narrowId = ulid();
const narrow = { id: narrowId, token: contextToken(narrowId, [`${APP_ID}.group-aset.read`], ulid(), ulid()) };

writeFileSync(OUTPUT, JSON.stringify({ appId: APP_ID, masters: MASTERS, tenants, narrow }, null, 0));
console.log(`wrote ${OUTPUT}: ${tenants.length} tenants + 1 narrow-permission tenant`);
