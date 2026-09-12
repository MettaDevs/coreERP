<?php

declare(strict_types=1);

namespace ControlPlane\Pelanggan;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Melahirkan satu pelanggan baru — dan tidak satu baris pun ditulisnya sendiri.
 *
 * Aksi ini memanggil Core lewat HTTP, lalu meneruskan jawabannya. Alasannya bukan kerapian
 * lapisan: yang tahu cara menjalankan migration module, membaca registry module, dan menyemai data
 * awal hanyalah Core. Menyalin `RegisterBusiness` ke konsol ini berarti dua salinan alur pembuatan
 * tenant, dan yang menyimpang di antara keduanya adalah rantai izin — tempat paling mahal untuk
 * menyimpan perbedaan yang tidak disengaja.
 *
 * Karena itu seluruh isi kelas ini adalah penanganan kegagalan. Jalur berhasilnya empat baris;
 * sisanya memastikan kegagalan sampai ke layar sebagai kalimat yang menyebut apa yang harus
 * diperbaiki, bukan sebagai halaman 500 yang menelan sebabnya.
 */
final class BuatPelanggan
{
    /**
     * @param  list<string>  $app  Id app yang dibeli. Ketersediaannya diputuskan Core, bukan di sini.
     * @return array{tenant_id: string, environment_id: ?string, email: string, kata_sandi_sementara: string}
     *
     * @throws PelangganDitolak Core menjawab, dan jawabannya "tidak".
     * @throws CoreTidakTerjangkau Permintaannya tidak sampai pada jawaban yang dapat dipakai.
     */
    public function __invoke(
        string $namaBadanHukum,
        string $namaAdmin,
        string $emailAdmin,
        array $app,
    ): array {
        $tujuan = $this->tujuan();

        try {
            $respons = Http::withToken((string) config('core.token'))
                ->acceptJson()
                ->timeout(max(1, (int) config('core.tenggat')))
                ->post($tujuan, [
                    'nama_badan_hukum' => $namaBadanHukum,
                    'nama_admin' => $namaAdmin,
                    'email_admin' => $emailAdmin,
                    'app_ids' => $app,
                ]);
        } catch (ConnectionException $putus) {
            // Sengaja tidak ditelan dan tidak dipercantik. Sebab yang paling sering adalah
            // `COREERP_URL` salah setel, dan satu-satunya cara operator dapat melihatnya adalah
            // kalau alamat yang dicoba ikut tertulis.
            throw new CoreTidakTerjangkau(
                'Core tidak menjawab di '.$tujuan.'. Periksa COREERP_URL di konsol ini dan pastikan '
                .'runtime Core memang hidup di alamat itu. Pesan aslinya: '.$putus->getMessage(),
                previous: $putus,
            );
        }

        if ($respons->failed()) {
            throw $this->alasanPenolakan($respons, $tujuan);
        }

        return $this->bacaJawaban($respons, $tujuan);
    }

    private function tujuan(): string
    {
        return rtrim((string) config('core.alamat'), '/').'/api/internal/v1/tenants';
    }

    /**
     * Menerjemahkan penolakan Core menjadi kalimat yang menyebut apa yang harus diperbaiki.
     *
     * Dua status dijawab dengan kalimat kita sendiri, bukan dengan kalimat Core. 401 dan 403 di
     * jalur ini hampir selalu berarti satu hal — kuncinya tidak cocok — sementara jawaban bawaan
     * Laravel untuk keduanya berbunyi "Unauthenticated.", yang tidak memberi tahu siapa pun bahwa
     * yang harus disunting adalah `CONTROL_PLANE_TOKEN` di berkas env konsol ini.
     */
    private function alasanPenolakan(Response $respons, string $tujuan): PelangganDitolak
    {
        $status = $respons->status();

        if ($status === 401 || $status === 403) {
            return new PelangganDitolak(
                'Core menolak kunci konsol ini (HTTP '.$status.' dari '.$tujuan.'). Nilai '
                .'CONTROL_PLANE_TOKEN di sini harus sama persis dengan yang diperiksa Core.',
            );
        }

        $isi = $respons->json();
        $isi = is_array($isi) ? $isi : [];

        $perIsian = $this->perIsian($isi['errors'] ?? null);
        $pesan = isset($isi['message']) && is_string($isi['message']) && $isi['message'] !== ''
            ? $isi['message']
            : '';

        if ($pesan === '' && $perIsian === []) {
            // Penolakan tanpa sebab tetap harus terbaca. Statusnya disebut karena itulah
            // satu-satunya keterangan yang benar-benar ada.
            $pesan = 'Core menolak permintaannya dengan HTTP '.$status.' dari '.$tujuan
                .', tanpa menyebut alasan.';
        }

        return new PelangganDitolak($pesan, $perIsian);
    }

    /**
     * Membersihkan peta galat per isian, dan membuang apa pun yang bentuknya tidak dikenali.
     *
     * Isinya datang dari aplikasi lain lewat jaringan, jadi ia diperlakukan sebagai bahan mentah:
     * kunci yang bukan teks dan pesan yang bukan teks dilewati, bukan dipaksa. Satu bentuk tak
     * terduga tidak boleh menjatuhkan seluruh penerjemahan galat — kalau ia jatuh, yang hilang
     * justru alasan penolakannya.
     *
     * @return array<string, list<string>>
     */
    private function perIsian(mixed $mentah): array
    {
        if (! is_array($mentah)) {
            return [];
        }

        $rapi = [];

        foreach ($mentah as $isian => $pesan) {
            if (! is_string($isian)) {
                continue;
            }

            $baris = [];

            foreach (is_array($pesan) ? $pesan : [$pesan] as $satu) {
                if (is_string($satu) && $satu !== '') {
                    $baris[] = $satu;
                }
            }

            if ($baris !== []) {
                $rapi[$isian] = $baris;
            }
        }

        return $rapi;
    }

    /**
     * @return array{tenant_id: string, environment_id: ?string, email: string, kata_sandi_sementara: string}
     */
    private function bacaJawaban(Response $respons, string $tujuan): array
    {
        $isi = $respons->json();
        $isi = is_array($isi) ? $isi : [];

        $tenant = $this->teks($isi, 'tenant_id');
        $email = $this->teks($isi, 'email');
        $kataSandi = $this->teks($isi, 'kata_sandi_sementara');

        if ($tenant === null || $email === null || $kataSandi === null) {
            /*
             * Jawaban 2xx yang tidak memuat kata sandi adalah keadaan paling berbahaya di seluruh
             * alur ini: pelanggannya mungkin **sudah lahir** di sisi Core, dan satu-satunya salinan
             * kata sandinya baru saja hilang. Karena itu pesannya tidak boleh berbunyi seperti
             * kegagalan biasa — ia harus menyuruh orangnya memeriksa daftar sebelum mencoba lagi,
             * supaya percobaan kedua tidak melahirkan pelanggan kembar.
             */
            throw new CoreTidakTerjangkau(
                'Core menjawab '.$respons->status().' dari '.$tujuan.', tetapi jawabannya tidak '
                .'memuat kata sandi sementara. Pelanggannya mungkin sudah terlanjur dibuat: '
                .'periksa daftar pelanggan sebelum mencoba lagi, dan pastikan COREERP_URL benar-'
                .'benar menunjuk runtime Core.',
            );
        }

        return [
            'tenant_id' => $tenant,
            // Boleh kosong, dan itu bukan data hilang: layar ini tidak membutuhkannya sama sekali.
            // Menggagalkan seluruh pembuatan karena satu id yang tidak dipakai berarti membuang
            // kata sandi yang justru menjadi alasan layar ini ada.
            'environment_id' => $this->teks($isi, 'environment_id'),
            'email' => $email,
            'kata_sandi_sementara' => $kataSandi,
        ];
    }

    /** @param array<mixed> $isi */
    private function teks(array $isi, string $kunci): ?string
    {
        $nilai = $isi[$kunci] ?? null;

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }
}
