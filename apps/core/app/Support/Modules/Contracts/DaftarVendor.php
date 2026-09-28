<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Vendor milik Core untuk module yang mencatat transaksi dengan pemasok (K-06).
 *
 * Module menyimpan `id` vendor, bukan nomor atau namanya. Nama milik party di buku alamat dan boleh
 * berubah; yang ditampilkan dibaca ulang lewat antarmuka ini, bukan salinan di tabel module.
 *
 * Setiap baris berbentuk `{id, number, name, tax_number, status, legal_entity_id}`.
 */
interface DaftarVendor
{
    /**
     * Vendor **aktif** satu entitas legal untuk dropdown, dicocokkan dengan nomor atau nama.
     *
     * @return list<array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}>
     */
    public function aktif(string $tenantId, string $legalEntityId, string $cari = '', int $batas = 20): array;

    /**
     * Satu vendor, termasuk yang sudah nonaktif — dokumen lama tetap harus bisa menampilkan
     * vendornya.
     *
     * @return array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}|null
     */
    public function satu(string $tenantId, string $vendorId): ?array;
}
