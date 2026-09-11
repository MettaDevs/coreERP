<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Sebuah tenant baru selesai dibuat, beserta daftar module yang dibelinya.
 *
 * Bentuknya sama persis dengan amplop `core.tenant.provisioned.v1` yang dikirim ke app di
 * luar proses, dan itu disengaja: satu fakta tidak boleh punya dua bentuk. Module yang kelak
 * dipasang kembali di luar proses membaca payload yang sama, hanya lewat pintu yang berbeda.
 *
 * `idEvent` adalah id baris outbox yang mencatat fakta yang sama. Dua jalur dengan id yang
 * sama membuat penerima dapat menghitung pemrosesan sekali walaupun ia menerima keduanya —
 * dan itu bukan kemungkinan teoretis: outbox bisa diputar ulang, dan sebuah module bisa
 * berpindah keluar dari proses ini.
 *
 * `data` berisi `app_ids`, yaitu module yang dibeli tenant tersebut. Penerima **wajib
 * memeriksanya**: event ini sampai ke setiap listener di runtime, termasuk module yang tidak
 * dibeli tenant itu.
 */
final class TenantDisiapkan
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $idEvent,
        public readonly string $tenantId,
        public readonly string $idKorelasi,
        public readonly ?string $legalEntityId,
        public readonly array $data,
    ) {}
}
