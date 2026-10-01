<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Membaca alamat dari buku alamat Core: tempat (`locations`, padanan `LogisticsLocation` Dynamics 365)
 * yang punya alamat pos.
 *
 * Module menyimpan id tempatnya sebagai teks opaque dan menerjemahkannya menjadi nama dan alamat saat
 * dibaca. Alamatnya sendiri tidak disalin ke module: mengubah alamat di buku alamat langsung berlaku di
 * setiap tempat yang menunjuknya. Module tidak membuat atau mengubah alamat lewat antarmuka ini; alamat
 * dipelihara di bagian Alamat organisasi.
 *
 * Yang dipulangkan baris sederhana: `nama` adalah nama tempat, `alamat` bentuk tercetaknya (beberapa
 * baris dipisah `\n`).
 */
interface AddressDirectory
{
    /**
     * Tempat beralamat pos milik tenant, urut nama, untuk dipilih.
     *
     * @return list<array{id: string, nama: string, alamat: string}>
     */
    public function postalAddresses(string $tenantId): array;

    /**
     * Tempat yang ditanyakan, berkunci id. Id yang tidak ada, milik tenant lain, atau tempat tanpa alamat
     * pos tidak ikut dipulangkan.
     *
     * @param  list<string>  $locationIds
     * @return array<string, array{id: string, nama: string, alamat: string}>
     */
    public function describe(string $tenantId, array $locationIds): array;

    /**
     * Alamat utama sebuah organisasi (entitas legal atau unit kerja), atau `null` bila belum punya.
     *
     * Dibaca tanpa membuat party organisasi, jadi sekadar bertanya tidak menulis apa pun.
     *
     * @return array{id: string, nama: string, alamat: string}|null
     */
    public function primaryOfOrganization(string $tenantId, string $organizationId): ?array;
}
