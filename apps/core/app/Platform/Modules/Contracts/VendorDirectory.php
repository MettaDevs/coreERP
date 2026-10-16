<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * Vendor milik Core untuk module yang mencatat transaksi dengan pemasok (K-06).
 *
 * Module menyimpan `id` vendor, bukan nomor atau namanya. Nama milik party di buku alamat dan boleh
 * berubah; yang ditampilkan dibaca ulang lewat antarmuka ini, bukan salinan di tabel module.
 *
 * Setiap baris berbentuk `{id, number, name, tax_number, status, legal_entity_id}`.
 */
interface VendorDirectory
{
    /** @param array<string, mixed> $input
     * @return array{data: mixed, next_cursor: ?string}
     */
    public function lookup(string $tenantId, string $legalEntityId, array $input): array;

    /** @param list<string> $ids
     * @return array<string, array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}>
     */
    public function findMany(string $tenantId, array $ids): array;

    /** @param array<string, string> $fields Alias nama vendor => kolom id vendor milik pemanggil. */
    public function addNames(Builder $query, string $tenantId, array $fields): void;

    /**
     * Vendor **aktif** satu entitas legal untuk dropdown, dicocokkan dengan nomor atau nama.
     *
     * @return list<array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}>
     */
    public function active(string $tenantId, string $legalEntityId, string $search = '', int $limit = 20): array;

    /**
     * Satu vendor, termasuk yang sudah nonaktif — dokumen lama tetap harus bisa menampilkan
     * vendornya.
     *
     * @return array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}|null
     */
    public function find(string $tenantId, string $vendorId): ?array;

    /** @return list<array{id: string, number: string, status: string, legal_entity_id: string, company: ?string}> */
    public function forParty(string $tenantId, string $partyId): array;
}
