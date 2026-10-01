<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\OrganizationDirectory;

/**
 * Unit kerja dan orang milik Core, diterjemahkan menjadi nama yang dikenali pengguna.
 *
 * Modul ini menyimpan id unit kerja dan id pengguna sebagai teks opaque — begitulah batas
 * antar app dijaga, dan itu tidak berubah. Yang berubah adalah apa yang **ditampilkan**:
 * sebuah ULID di kolom "unit tujuan" atau di baris tanda tangan berita acara tidak berarti
 * apa-apa bagi orang yang membacanya, dan berita acara yang tidak menyebut nama siapa pun
 * tidak dapat dipakai sebagai bukti serah terima.
 *
 * Penerjemahan sengaja dikerjakan saat **dibaca**, bukan disimpan sebagai snapshot nama.
 * Nama orang dan nama unit berubah karena sebab yang tidak ada hubungannya dengan aset —
 * pernikahan, reorganisasi, pembetulan ejaan — dan dokumen yang membekukan nama akan
 * menampilkan ejaan lama selamanya tanpa ada yang bisa membetulkannya.
 *
 * Pembacaan dihafal per permintaan. Satu layar daftar menerjemahkan puluhan baris yang
 * menunjuk segelintir unit yang sama, dan tanpa hafalan itu berarti puluhan pembacaan
 * direktori untuk jawaban yang selalu sama.
 */
final class AssetOrganizationDirectory
{
    /** @var array<string, array<string, string>> */
    private array $unitCache = [];

    /** @var array<string, array<string, string>> */
    private array $memberCache = [];

    public function __construct(private readonly OrganizationDirectory $directory) {}

    /**
     * Unit kerja tenant sebagai pilihan dropdown.
     *
     * @return list<array{id: string, nama: string}>
     */
    public function operatingUnits(string $tenantId): array
    {
        return array_map(
            static fn (array $unit): array => ['id' => $unit['id'], 'nama' => $unit['nama']],
            $this->directory->operatingUnits($tenantId),
        );
    }

    /**
     * Anggota tenant sebagai pilihan dropdown, berkunci id **pengguna**.
     *
     * Id pengguna, bukan id keanggotaan, karena itulah yang dibawa konteks permintaan
     * (`coreerp.user_id`) dan yang sudah tersimpan di kolom penanggung jawab modul ini.
     * Memakai id keanggotaan di sini akan membuat nilai yang dipilih dari dropdown tidak
     * pernah cocok dengan nilai yang sudah ada di database.
     *
     * @return list<array{id: string, nama: string, email: string}>
     */
    public function members(string $tenantId): array
    {
        return array_map(
            static fn (array $members): array => [
                'id' => $members['user_id'],
                'nama' => $members['nama'],
                'email' => $members['email'],
            ],
            $this->directory->members($tenantId),
        );
    }

    /** Nama satu unit kerja; `null` bila idnya kosong atau unitnya sudah tidak ada. */
    public function unitName(string $tenantId, ?string $unitId): ?string
    {
        return $unitId === null || $unitId === ''
            ? null
            : ($this->unitMap($tenantId)[$unitId] ?? null);
    }

    /** Nama satu orang; `null` bila idnya kosong atau keanggotaannya sudah dicabut. */
    public function personName(string $tenantId, ?string $userId): ?string
    {
        return $userId === null || $userId === ''
            ? null
            : ($this->memberMap($tenantId)[$userId] ?? null);
    }

    /** @return array<string, string> */
    private function unitMap(string $tenantId): array
    {
        return $this->unitCache[$tenantId] ??= array_column(
            $this->directory->operatingUnits($tenantId),
            'nama',
            'id',
        );
    }

    /** @return array<string, string> */
    private function memberMap(string $tenantId): array
    {
        return $this->memberCache[$tenantId] ??= array_column(
            $this->directory->members($tenantId),
            'nama',
            'user_id',
        );
    }
}
