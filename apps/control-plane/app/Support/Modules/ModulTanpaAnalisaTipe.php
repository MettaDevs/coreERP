<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * Module yang belum ikut analisa tipe statis, beserta alasan dan tenggatnya.
 *
 * **Kenapa daftarnya terpisah dari `ModulSedangDipindah`.** Sampai 9 September 2026 keduanya
 * satu daftar, dengan anggapan seluruh pengecualian sebuah module berakhir bersamaan. Anggapan
 * itu terbukti salah pada hari modul aset selesai dipindah: ia lulus kelima penjaga batas,
 * lulus pemeriksaan tipe frontend, dan lulus pemeriksaan gaya — sambil masih menyisakan 405
 * temuan analisa tipe PHP.
 *
 * "Bersih menurut batas" dan "bersih menurut tipe" ternyata dua tonggak yang berbeda, dan
 * menyatukannya berarti tonggak yang lebih lambat menyandera yang lebih cepat: modul harus
 * tetap dianggap sedang dipindah — dengan kelima penjaga batasnya ikut mati — hanya karena
 * anotasi tipenya belum ditulis.
 *
 * **Yang tidak boleh terjadi: daftar ini menjadi tempat sembunyi.** Karena itu bentuknya sama
 * dengan `ModulSedangDipindah` — alasan yang menyebut angka terukur, dan tenggat. Keduanya
 * dijaga `ModulTanpaAnalisaTipeTest`: daftar ini wajib sama persis dengan `excludePaths` pada
 * `phpstan.neon`, dan tenggat yang lewat membuat alur merah.
 */
final class ModulTanpaAnalisaTipe
{
    /**
     * Nama folder modul dipetakan ke alasan dan tenggatnya.
     *
     * @var array<string, array{alasan: string, tenggat: string}>
     */
    private const DAFTAR = [
        // Kosong sejak 9 September 2026. Modul aset — satu-satunya yang pernah terdaftar di
        // sini — selesai dianotasi pada F3-29, dan sejak itu seluruh modul ikut analisa tipe
        // tanpa kecuali. Kelasnya tetap ada karena modul berikutnya akan mendarat dengan
        // keadaan yang sama.
    ];

    /**
     * @param  array<string, array{alasan: string, tenggat: string}>  $daftar
     */
    private function __construct(private readonly array $daftar) {}

    public static function bawaan(): self
    {
        return new self(self::DAFTAR);
    }

    /**
     * Daftar buatan, hanya untuk test yang membuktikan perilaku daftar ini.
     *
     * @param  array<string, array{alasan: string, tenggat: string}>  $daftar
     */
    public static function dariDaftar(array $daftar): self
    {
        return new self($daftar);
    }

    public function menandai(string $namaFolder): bool
    {
        return isset($this->daftar[$namaFolder]);
    }

    /**
     * @return array<string, array{alasan: string, tenggat: string}>
     */
    public function semua(): array
    {
        return $this->daftar;
    }

    /**
     * Entri yang tenggatnya sudah lewat.
     *
     * Tanggalnya dioper, bukan dibaca dari jam sistem, supaya testnya dapat membuktikan
     * penjaga ini benar-benar bisa merah tanpa menunggu tanggalnya tiba.
     *
     * @return list<string>
     */
    public function tenggatYangLewat(\DateTimeImmutable $hariIni): array
    {
        $lewat = [];

        foreach ($this->daftar as $nama => $entri) {
            $tenggat = \DateTimeImmutable::createFromFormat('!Y-m-d', $entri['tenggat']);

            if ($tenggat !== false && $tenggat < $hariIni) {
                $lewat[] = $nama;
            }
        }

        sort($lewat);

        return $lewat;
    }

    /**
     * Nama folder yang terdaftar, diurutkan supaya perbandingan dengan berkas setelan stabil.
     *
     * @return list<string>
     */
    public function namaFolder(): array
    {
        $nama = array_keys($this->daftar);
        sort($nama);

        return $nama;
    }
}
