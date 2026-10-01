<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

/**
 * Module yang belum ikut analisa tipe statis, beserta alasan dan tenggatnya.
 *
 * **Kenapa daftarnya terpisah dari `ModulesBeingMoved`.** Sampai 9 September 2026 keduanya
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
 * dengan `ModulesBeingMoved` — alasan yang menyebut angka terukur, dan tenggat. Keduanya
 * dijaga `ModulesWithoutTypeAnalysisTest`: daftar ini wajib sama persis dengan `excludePaths` pada
 * `phpstan.neon`, dan tenggat yang lewat membuat alur merah.
 */
final class ModulesWithoutTypeAnalysis
{
    /**
     * Nama folder modul dipetakan ke alasan dan tenggatnya.
     *
     * @var array<string, array{alasan: string, tenggat: string}>
     */
    private const MODULES = [
        // Kosong sejak 9 September 2026. Modul aset — satu-satunya yang pernah terdaftar di
        // sini — selesai dianotasi pada F3-29, dan sejak itu seluruh modul ikut analisa tipe
        // tanpa kecuali. Kelasnya tetap ada karena modul berikutnya akan mendarat dengan
        // keadaan yang sama.
    ];

    /**
     * @param  array<string, array{alasan: string, tenggat: string}>  $list
     */
    private function __construct(private readonly array $list) {}

    public static function default(): self
    {
        return new self(self::MODULES);
    }

    /**
     * Daftar buatan, hanya untuk test yang membuktikan perilaku daftar ini.
     *
     * @param  array<string, array{alasan: string, tenggat: string}>  $list
     */
    public static function fromList(array $list): self
    {
        return new self($list);
    }

    public function marks(string $folderName): bool
    {
        return isset($this->list[$folderName]);
    }

    /**
     * @return array<string, array{alasan: string, tenggat: string}>
     */
    public function all(): array
    {
        return $this->list;
    }

    /**
     * Entri yang tenggatnya sudah lewat.
     *
     * Tanggalnya dioper, bukan dibaca dari jam sistem, supaya testnya dapat membuktikan
     * penjaga ini benar-benar bisa merah tanpa menunggu tanggalnya tiba.
     *
     * @return list<string>
     */
    public function overdue(\DateTimeImmutable $today): array
    {
        $overdue = [];

        foreach ($this->list as $name => $entry) {
            $deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $entry['tenggat']);

            if ($deadline !== false && $deadline < $today) {
                $overdue[] = $name;
            }
        }

        sort($overdue);

        return $overdue;
    }

    /**
     * Nama folder yang terdaftar, diurutkan supaya perbandingan dengan berkas setelan stabil.
     *
     * @return list<string>
     */
    public function folderNames(): array
    {
        $name = array_keys($this->list);
        sort($name);

        return $name;
    }
}
