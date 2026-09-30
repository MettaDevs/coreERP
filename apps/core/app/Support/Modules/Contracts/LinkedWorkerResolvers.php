<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Daftar penjawab pekerja tertaut, diisi penyedia layanan tiap module saat boot.
 *
 * Satu benda untuk seluruh proses (`CoreServices::PEMETAAN_TUNGGAL`), supaya pendaftaran module dan layar
 * anggota Core memegang daftar yang sama. Tanpa penjawab, atau tanpa module pemiliknya terpasang, layar
 * anggota tidak menampilkan pekerja.
 */
interface LinkedWorkerResolvers
{
    public function register(LinkedWorkerResolver $resolver): void;

    /** Apakah ada module pemilik data pekerja yang terpasang untuk tenant itu. */
    public function availableFor(string $tenantId): bool;

    /**
     * Pekerja tertaut per keanggotaan, dari module yang terpasang untuk tenant itu.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, array{name: string, personnel_number: string}> Berkunci id keanggotaan.
     */
    public function forMemberships(string $tenantId, array $membershipIds): array;
}
