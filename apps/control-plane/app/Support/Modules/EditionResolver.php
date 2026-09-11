<?php

declare(strict_types=1);

namespace App\Support\Modules;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Menghitung daftar modul yang benar-benar masuk ke sebuah image edisi.
 *
 * Manifest edisi hanya menyebut modul yang **dibeli**. Yang dikirim ke server pelanggan lebih
 * dari itu, dan lebih sedikit dari "semua yang ada di repo". Kelas ini yang menerjemahkannya.
 *
 * **Kenapa ia tidak memakai `AppDependencyGraph`.** Rencananya begitu, dan itu tidak bisa:
 * graph itu membaca tabel `apps`, sedangkan image edisi dibangun di CI tanpa database sama
 * sekali. Sumber kebenaran dependency di sini `depends_on` pada tiap `app.yaml` — berkas yang
 * ikut di dalam repo, dan yang sama dengan yang dibaca runtime.
 *
 * **Kenapa ia menolak, bukan menyaring diam-diam.** Id yang tidak ada di repo, dan modul bahan
 * uji yang disebut sebuah edisi, keduanya kesalahan penulisan manifest. Menyaringnya tanpa
 * suara menghasilkan image yang berhasil dibangun dan kekurangan modul yang dibayar pelanggan —
 * kegagalan yang baru ketahuan di tangan pengguna.
 */
final readonly class EditionResolver
{
    public function __construct(private ModuleRegistry $registry) {}

    /**
     * Daftar id modul untuk sebuah manifest edisi, sudah diurutkan.
     *
     * @return list<string>
     */
    public function dariBerkas(string $berkas): array
    {
        if (! is_file($berkas)) {
            throw new RuntimeException(sprintf('Manifest edisi %s tidak ditemukan.', $berkas));
        }

        try {
            /** @var mixed $isi */
            $isi = Yaml::parseFile($berkas);
        } catch (ParseException $kesalahan) {
            throw new RuntimeException(sprintf('Manifest edisi %s bukan YAML yang valid: %s', $berkas, $kesalahan->getMessage()));
        }

        if (! is_array($isi)) {
            throw new RuntimeException(sprintf('Manifest edisi %s harus berupa map di level teratas.', $berkas));
        }

        $dibeli = $isi['modul'] ?? [];

        if (! is_array($dibeli)) {
            throw new RuntimeException(sprintf('Kunci `modul` pada %s harus berupa daftar.', $berkas));
        }

        return $this->dariDaftar(array_values(array_filter($dibeli, 'is_string')));
    }

    /**
     * Bentuk yang sama, tetapi daftar belinya dioper langsung.
     *
     * Ada supaya test dapat membuktikan aturannya tanpa menulis berkas edisi lebih dulu.
     *
     * @param  list<string>  $dibeli
     * @return list<string>
     */
    public function dariDaftar(array $dibeli): array
    {
        $semua = $this->modulDiRepo();
        $terpilih = [];

        foreach ($dibeli as $id) {
            $this->pastikanBolehDibeli($id, $semua);
            $this->tutupDependency($id, $semua, $terpilih);
        }

        $this->tambahkanPenghubung($semua, $terpilih);

        $hasil = array_keys($terpilih);
        sort($hasil);

        return $hasil;
    }

    /**
     * Modul yang dibeli wajib ada di repo dan tidak boleh bahan uji internal.
     *
     * @param  array<string, ModuleManifest>  $semua
     */
    private function pastikanBolehDibeli(string $id, array $semua): void
    {
        $manifest = $semua[$id] ?? null;

        if ($manifest === null) {
            throw new RuntimeException(sprintf(
                'Edisi menyebut modul "%s", yang tidak ada di repo. Yang ada: %s.',
                $id,
                implode(', ', array_keys($semua)) ?: 'tidak ada satu pun',
            ));
        }

        if ($manifest->bahanUjiInternal()) {
            throw new RuntimeException(sprintf(
                'Modul "%s" adalah bahan uji internal dan tidak boleh masuk edisi pelanggan mana pun. '.
                'Ia hidup di repo untuk menguji penjaga batas; sebuah menu bernama "%s" di layar '.
                'pelanggan adalah kegagalan yang tidak boleh mungkin terjadi.',
                $id,
                $manifest->nama,
            ));
        }
    }

    /**
     * Menutup dependency sebuah modul secara transitif ke dalam `$terpilih`.
     *
     * Modul penghubung sengaja **tidak** ditarik masuk dari sini. Ia bukan prasyarat siapa pun;
     * ia justru yang menunggu sisi-sisinya lengkap, dan itu diputuskan setelah seluruh
     * dependency selesai ditutup.
     *
     * @param  array<string, ModuleManifest>  $semua
     * @param  array<string, true>  $terpilih
     */
    private function tutupDependency(string $id, array $semua, array &$terpilih): void
    {
        if (isset($terpilih[$id])) {
            return;
        }

        $manifest = $semua[$id] ?? null;

        if ($manifest === null) {
            throw new RuntimeException(sprintf(
                'Modul "%s" dibutuhkan sebagai dependency tetapi tidak ada di repo.',
                $id,
            ));
        }

        // Ditandai sebelum turun ke dependency-nya. Manifest yang saling menunjuk — sengaja
        // maupun salah tulis — akan membuat penelusuran ini tidak pernah berhenti kalau
        // penandaannya menunggu sampai anak-anaknya selesai.
        $terpilih[$id] = true;

        foreach ($manifest->dependency as $butuh) {
            $this->tutupDependency($butuh, $semua, $terpilih);
        }
    }

    /**
     * Modul penghubung ikut hanya bila seluruh sisi yang dihubungkannya sudah terpilih.
     *
     * Diulang sampai tidak ada tambahan, karena sebuah penghubung boleh bergantung pada
     * penghubung lain. Sekali jalan akan melewatkannya, dan yang terlewat itu tidak berbunyi:
     * image-nya tetap terbangun, hanya integrasinya yang diam-diam tidak ada.
     *
     * @param  array<string, ModuleManifest>  $semua
     * @param  array<string, true>  $terpilih
     */
    private function tambahkanPenghubung(array $semua, array &$terpilih): void
    {
        do {
            $bertambah = false;

            foreach ($semua as $id => $manifest) {
                if (isset($terpilih[$id]) || $manifest->jenis !== 'link') {
                    continue;
                }

                if ($manifest->dependency === []) {
                    continue;
                }

                foreach ($manifest->dependency as $sisi) {
                    if (! isset($terpilih[$sisi])) {
                        continue 2;
                    }
                }

                $terpilih[$id] = true;
                $bertambah = true;
            }
        } while ($bertambah);
    }

    /**
     * Seluruh modul di repo, berkunci id.
     *
     * Yang dibaca `semuaTermasukYangSedangDipindah()`: sebuah modul yang sedang dipindah masuk
     * belum dilayani, tetapi berkasnya sudah ada dan pertanyaan di sini bukan "boleh dipasang
     * tenant mana" melainkan "berkas mana yang ikut ke image". Yang menolak modul yang tidak
     * pantas dikirim adalah pemeriksaan `kind`, bukan daftar pemindahan.
     *
     * @return array<string, ModuleManifest>
     */
    private function modulDiRepo(): array
    {
        $hasil = [];

        foreach ($this->registry->semuaTermasukYangSedangDipindah() as $manifest) {
            $hasil[$manifest->id] = $manifest;
        }

        return $hasil;
    }
}
