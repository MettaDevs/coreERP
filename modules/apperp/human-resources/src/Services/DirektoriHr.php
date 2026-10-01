<?php

namespace Modules\Apperp\HumanResources\Services;

use App\Platform\Modules\Contracts\OrganizationDirectory;

/**
 * Anggota dan unit kerja tenant lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan klien yang dulu memanggil tiga endpoint internal Core. Di dalam satu runtime
 * permintaan itu tidak menambah satu pun kemampuan; ia hanya menambah kegagalan yang bisa
 * terjadi — batas waktu, token layanan yang tidak sinkron, dan 503 yang harus dijelaskan ke
 * pengguna padahal Core berada di proses yang sama.
 *
 * **Tanda tangan dan bentuk jawabannya sengaja dipertahankan persis seperti milik klien
 * lama.** Kontrak Core memakai kunci `id`/`nama`, sedangkan endpoint module ini sejak awal
 * memulangkan `membership_id`/`name`. Yang berpindah adalah jalurnya, bukan janjinya ke
 * pemanggil, jadi penerjemahan kunci dikerjakan di sini — satu tempat, bukan di setiap
 * controller yang memakainya.
 */
final class DirektoriHr
{
    /**
     * Sebanyak-banyaknya anggota yang dipulangkan pencarian.
     *
     * Angkanya diambil dari endpoint lama apa adanya. Ia bukan kerapian: daftar ini mengisi
     * kotak pencarian pada layar penautan akun, dan sebuah tenant besar akan mengirim ribuan
     * baris ke layar yang hanya menampilkan beberapa.
     */
    private const BATAS_HASIL = 20;

    public function __construct(private readonly OrganizationDirectory $direktori) {}

    /**
     * Anggota tenant yang cocok dengan kata pencarian.
     *
     * Penyaringan dan pembatasan dikerjakan di sini, bukan oleh kontrak. Kontrak Core
     * memulangkan seluruh anggota aktif tanpa parameter pencarian, jadi menghapus penyaringan
     * ini berarti mengubah perilaku endpoint — kata pencarian yang dikirim layar berhenti
     * berpengaruh, dan yang dipulangkan menjadi seluruh isi direktori.
     *
     * @return list<array{membership_id: string, name: string, email: string}>
     */
    public function members(string $tenantId, string $query): array
    {
        $cari = mb_strtolower(trim($query));
        $hasil = [];

        foreach ($this->direktori->members($tenantId) as $anggota) {
            if ($cari !== ''
                && ! str_contains(mb_strtolower($anggota['nama']), $cari)
                && ! str_contains(mb_strtolower($anggota['email']), $cari)) {
                continue;
            }

            $hasil[] = $this->bentukAnggota($anggota);

            if (count($hasil) === self::BATAS_HASIL) {
                break;
            }
        }

        return $hasil;
    }

    /**
     * Unit kerja tenant, dengan kunci yang sama seperti yang dipulangkan endpoint lama.
     *
     * @return list<array{id: string, name: string}>
     */
    public function operatingUnits(string $tenantId): array
    {
        $hasil = [];

        foreach ($this->direktori->operatingUnits($tenantId) as $unit) {
            $hasil[] = ['id' => $unit['id'], 'name' => $unit['nama']];
        }

        return $hasil;
    }

    /**
     * Anggota aktif tenant yang emailnya sama persis dengan email pekerja, tanpa membedakan huruf besar
     * (TODO analisa gap BC 9.1). Dipakai sebagai usulan tautan pekerja; yang memutuskan tetap
     * pengguna. Hanya anggota tenant itu yang bisa muncul, karena kontrak Core menyaring per tenant.
     *
     * @return list<array{membership_id: string, name: string, email: string}>
     */
    public function membersWithEmail(string $tenantId, string $email): array
    {
        $dicari = mb_strtolower(trim($email));
        if ($dicari === '') {
            return [];
        }

        $hasil = [];
        foreach ($this->direktori->members($tenantId) as $anggota) {
            if (mb_strtolower(trim($anggota['email'])) === $dicari) {
                $hasil[] = $this->bentukAnggota($anggota);
            }
        }

        return $hasil;
    }

    /**
     * Anggota aktif tenant berkunci id keanggotaan, untuk menampilkan nama akun yang tertaut di daftar pekerja.
     * Keanggotaan yang tidak aktif lagi tidak ikut.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, array{membership_id: string, name: string, email: string}>
     */
    public function membersById(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $dicari = array_flip($membershipIds);
        $hasil = [];
        foreach ($this->direktori->members($tenantId) as $anggota) {
            if (isset($dicari[$anggota['id']])) {
                $hasil[$anggota['id']] = $this->bentukAnggota($anggota);
            }
        }

        return $hasil;
    }

    /**
     * Satu anggota tenant, dipakai untuk memastikan keanggotaan yang ditautkan memang milik tenant ini.
     * `null` bila tidak ada, termasuk keanggotaan milik tenant lain: yang memanggilnya sedang memvalidasi
     * masukan pengguna, dan jawabannya galat isian, bukan kegagalan server.
     *
     * @return array{membership_id: string, name: string, email: string}|null
     */
    public function member(string $tenantId, string $membershipId): ?array
    {
        $anggota = $this->direktori->member($tenantId, $membershipId);

        return $anggota === null ? null : $this->bentukAnggota($anggota);
    }

    /**
     * @param  array{id: string, nama: string, email: string}  $anggota
     * @return array{membership_id: string, name: string, email: string}
     */
    private function bentukAnggota(array $anggota): array
    {
        return [
            'membership_id' => $anggota['id'],
            'name' => $anggota['nama'],
            'email' => $anggota['email'],
        ];
    }
}
