<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Satuan ukur milik tenant, beserta konversinya.
 */
interface DaftarSatuan
{
    /**
     * Satuan aktif milik sebuah tenant, untuk layar yang menampilkan pilihan.
     *
     * Ditambahkan pada F3-08. Kontrak versi pertama hanya punya `resolusi()` dan `konversi()`,
     * dan keduanya menuntut id yang sudah diketahui — module yang perlu **menampilkan** daftar
     * pilihan tidak punya pintu resmi sama sekali. Yang tidak punya pintu resmi akan menyentuh
     * model Core langsung, dan batasnya kembali kabur.
     *
     * @return list<array<string, mixed>>
     */
    public function aktif(string $tenantId): array;

    /**
     * @param  list<string>  $id
     * @return array<string, mixed>
     */
    public function resolusi(string $tenantId, array $id): array;

    /** @return array<string, mixed> */
    public function konversi(string $tenantId, string $dari, string $ke, string $nilai): array;
}
