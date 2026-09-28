<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Daftar akun referensi milik aplikasi finance pelanggan, untuk pemetaan posting di module (K-05).
 *
 * Module menyimpan `id` akun, bukan nomornya. Nomor dan nama boleh berubah lewat impor ulang tanpa
 * memutus pemetaan; yang dibaca saat menampilkan atau menerbitkan posting selalu nilai terbaru.
 *
 * Setiap baris berbentuk `{id, external_id, code, name, type, active, legal_entity_id}`. `type`
 * adalah `balance_sheet` atau `profit_loss`; `legal_entity_id` kosong berarti akun berlaku untuk
 * semua entitas legal tenant.
 */
interface DaftarAkun
{
    /**
     * Akun **aktif** untuk dropdown, dicocokkan dengan nomor, nama, atau `external_id`.
     *
     * Dengan `$legalEntityId`: akun khusus entitas itu dan akun yang berlaku untuk semua entitas.
     * Tanpa `$legalEntityId`: hanya akun yang berlaku untuk semua entitas, karena pemetaan tingkat
     * tenant tidak boleh menunjuk akun milik satu entitas saja.
     *
     * @return list<array{id: string, external_id: string, code: string, name: string, type: string, active: bool, legal_entity_id: ?string}>
     */
    public function cari(string $tenantId, ?string $legalEntityId, string $kata = '', int $batas = 20): array;

    /**
     * Satu akun, termasuk yang sudah nonaktif — pemetaan lama tetap harus bisa ditampilkan.
     *
     * @return array{id: string, external_id: string, code: string, name: string, type: string, active: bool, legal_entity_id: ?string}|null
     */
    public function satu(string $tenantId, string $accountId): ?array;

    /**
     * Banyak akun sekaligus, berkunci id, termasuk yang nonaktif. Untuk layar yang menampilkan
     * puluhan pemetaan tanpa satu pembacaan per sel.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array{id: string, external_id: string, code: string, name: string, type: string, active: bool, legal_entity_id: ?string}>
     */
    public function banyak(string $tenantId, array $accountIds): array;
}
