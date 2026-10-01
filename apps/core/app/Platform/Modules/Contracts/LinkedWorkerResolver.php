<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Pekerja yang tertaut ke keanggotaan tenant, dijawab module pemilik data pekerja (gap 3, TODO analisa gap
 * BC 9.2).
 *
 * Tautannya disimpan module, bukan Core: pekerja bukan pengguna, dan tidak setiap pengguna menjadi pekerja
 * (padanan *associate user with person* di F&O dan `User Setup` → `Employee No.` di BC). Layar anggota Core
 * menampilkan nama pekerjanya tanpa membaca tabel module; ia bertanya lewat kontrak ini.
 *
 * Module mendaftarkannya ke {@see LinkedWorkerResolvers} dari penyedia layanannya. Core hanya bertanya
 * kepada module yang terpasang untuk tenant itu, jadi module tidak perlu memeriksa pemasangannya sendiri.
 */
interface LinkedWorkerResolver
{
    /** Id module pemilik data pekerja. */
    public function moduleId(): string;

    /**
     * Pekerja yang belum diarsipkan dan tertaut ke keanggotaan yang ditanyakan, hanya milik tenant itu.
     * Keanggotaan tanpa pekerja tidak ikut dipulangkan.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, array{name: string, personnel_number: string}> Berkunci id keanggotaan.
     */
    public function forMemberships(string $tenantId, array $membershipIds): array;
}
