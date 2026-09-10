<?php

namespace Modules\Apperp\HumanResources\Services;

use App\Support\Modules\Contracts\DirektoriOrganisasi;
use RuntimeException;

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

    public function __construct(private readonly DirektoriOrganisasi $direktori) {}

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

        foreach ($this->direktori->anggota($tenantId) as $anggota) {
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

        foreach ($this->direktori->unitOperasi($tenantId) as $unit) {
            $hasil[] = ['id' => $unit['id'], 'name' => $unit['nama']];
        }

        return $hasil;
    }

    /**
     * Satu anggota, dipakai untuk memastikan keanggotaan yang ditautkan memang ada.
     *
     * Melempar bila tidak ada, sama seperti klien lama: yang memanggilnya sedang memvalidasi
     * masukan pengguna, dan anggota yang tidak ditemukan berarti masukannya tidak sah.
     *
     * @return array{membership_id: string, name: string, email: string}
     */
    public function member(string $tenantId, string $membershipId): array
    {
        $anggota = $this->direktori->anggotaSatu($tenantId, $membershipId);

        if ($anggota === null) {
            throw new RuntimeException('Anggota Core tidak tersedia.');
        }

        return $this->bentukAnggota($anggota);
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
