<?php

declare(strict_types=1);

namespace App\Support\Modules;

use DateTimeImmutable;

/**
 * Satu tempat bersama: modul mana yang sedang dipindah masuk dan belum dibentuk ulang.
 *
 * Ketiga penjaga batas membaca daftar ini. Alasannya begini.
 *
 * F3-01 memindahkan repo modul apa adanya, tanpa mengubah satu berkas pun di dalamnya —
 * supaya `git log` dan `git blame` ikut pindah, dan supaya pull request pemindahan bisa
 * ditinjau sebagai "hanya berpindah tempat". Akibatnya, begitu subtree mendarat, ketiga
 * penjaga merah sekaligus: kodenya masih ber-namespace `App\`, masih memanggil `DB::table(`,
 * dan manifestnya belum menyatakan awalan tabel. Penjaganya benar; yang belum lengkap adalah
 * rencananya. Daftar ini melengkapinya, untuk modul yang disebut di sini saja.
 *
 * **Kenapa penandanya di sini, bukan di `app.yaml` modul.** Dua alasan, yang kedua yang
 * menentukan. Pertama, F3-01 menyatakan tidak mengubah apa pun di dalam subtree. Kedua, dan
 * ini yang tidak bisa ditawar: `app.yaml` berada **di dalam** subtree, jadi penanda di sana
 * akan terhapus setiap kali subtree ditarik ulang dari repo asalnya — repo yang tidak tahu
 * apa-apa tentang CoreERP dan tidak punya alasan untuk menjaga baris itu tetap ada.
 *
 * **Kenapa dikunci pada nama folder, bukan `id` manifest.** Penjaga namespace tidak pernah
 * membaca `app.yaml` sama sekali, dan modul yang belum dibentuk ulang mungkin manifestnya
 * belum terbaca dengan bentuk yang diharapkan. Nama folder adalah satu-satunya penanda yang
 * sudah dipegang ketiga penjaga tanpa syarat.
 *
 * **Dua cara pengecualian ini berakhir.** Keduanya harus ada, karena masing-masing menjawab
 * pertanyaan yang berbeda.
 *
 * 1. `tenggat` menjawab "kapan ini harus selesai". Setelah lewat, `ModulSedangDipindahTest`
 *    membuat alur merah. Tidak ada cara memperpanjangnya diam-diam; memperpanjang berarti
 *    mengubah baris di berkas ini dan baris itu terlihat pada diff.
 * 2. Pemeriksaan basi menjawab "bagaimana orang tahu ini sudah boleh dibuang". Modul yang
 *    dikecualikan tetap dipindai penuh; bila ternyata sudah tidak melanggar apa pun, yang
 *    gagal justru entrinya sendiri. Ini pengecualian sebagai pembalik, bukan pelewat: ia
 *    tidak melewatkan pemindaian, ia hanya membalik arti hasilnya.
 *
 * Ditulis sebagai konstanta di dalam kode, bukan di berkas setelan, mengikuti pola
 * `PENGECUALIAN` pada penjaga tabel: pengecualian baru wajib terlihat pada diff pull request.
 */
/**
 * Berkas ini pindah dari `tests/` ke kode aplikasi pada F3-04, dan alasannya perlu diketahui.
 *
 * F3-25 memakai `table_prefix` yang belum dinyatakan sebagai tanda "module ini belum siap
 * dilayani". Tanda itu bekerja tepat sampai F3-04, yaitu task yang justru memberi awalan tabel —
 * dan dengan itu menyalakan module yang tenant_id-nya belum ada, query mentahnya belum diganti,
 * dan panggilan HTTP-nya belum dibuang. Tanda kesiapan yang ikut berubah karena pekerjaan
 * setengah jalan bukan tanda kesiapan.
 *
 * Sekarang daftar inilah tandanya, dan ia hanya berubah kalau ada yang sengaja mengubahnya.
 * Karena registry membacanya, ia harus berada di kode aplikasi; penjaga batas membaca daftar
 * yang sama supaya tidak ada dua daftar yang bisa menyimpang.
 */
final class ModulSedangDipindah
{
    /**
     * Nama folder modul dipetakan ke alasan dan tenggatnya.
     *
     * Angka pada `alasan` bukan perkiraan. Semuanya diukur pada repo aset apa adanya sebelum
     * pemindahan, dan dicatat di `docs/todo/satu-runtime/01-prd.md` bagian F3-00.
     *
     * **`pemblokir` opsional, dan sebaiknya tetap kosong.** Ia diisi hanya ketika modulnya sudah
     * bersih menurut pemindaian berkas tetapi entrinya masih belum boleh dibuang karena sesuatu
     * yang tidak dapat dilihat pemindai mana pun. Premis semula — "bersih berarti entrinya basi" —
     * terbukti salah pada 9 September 2026: modul aset lulus seluruh pemindaian, tetapi membuang
     * entrinya membuatnya **dilayani**, dan itu menjatuhkan 84 test Core yang fixture katalognya
     * belum menggambarkannya sebagai app yang dapat dipasang. Sebabnya dibereskan F3-30 —
     * fixture itu berhenti memakai id modul sungguhan — dan entrinya dibuang bersamanya.
     *
     * Isinya wajib menyebut nomor task yang membuangnya, supaya ia tidak bisa menjadi alasan yang
     * berlaku selamanya. Tenggat tetap berlaku penuh.
     *
     * @var array<string, array{alasan: string, tenggat: string, pemblokir?: string}>
     */
    private const DAFTAR = [
        // Kosong lagi sejak 10 September 2026. Dua module sudah melewati daftar ini — aset pada
        // F3-30, human-resources pada F7-01 — dan yang kedua tinggal di sini kurang dari satu hari.
        // Itu ukuran yang paling berguna dari daftar ini: bukan berapa lama ia kosong, melainkan
        // berapa lama sebuah entri bertahan.
    ];

    /**
     * @param  array<string, array{alasan: string, tenggat: string, pemblokir?: string}>  $daftar
     */
    private function __construct(private readonly array $daftar) {}

    /**
     * Daftar yang sebenarnya dipakai ketiga penjaga.
     */
    public static function bawaan(): self
    {
        return new self(self::DAFTAR);
    }

    /**
     * Daftar buatan, hanya untuk test yang membuktikan perilaku daftar ini.
     *
     * Tanpa pintu ini, satu-satunya cara menguji "melonggarkan untuk satu modul tidak
     * melonggarkan untuk modul lain" adalah menambah modul sungguhan ke daftar sungguhan,
     * dan itu berarti test-nya ikut berubah setiap kali daftarnya berubah.
     *
     * @param  array<string, array{alasan: string, tenggat: string, pemblokir?: string}>  $daftar
     */
    public static function buatan(array $daftar): self
    {
        return new self($daftar);
    }

    /**
     * Apakah folder modul ini sedang dipindah dan belum dibentuk ulang?
     */
    public function menandai(string $namaFolder): bool
    {
        return array_key_exists($namaFolder, $this->daftar);
    }

    /**
     * @return array<string, array{alasan: string, tenggat: string, pemblokir?: string}>
     */
    public function semua(): array
    {
        return $this->daftar;
    }

    /**
     * Nama folder yang tenggatnya sudah lewat, beserta tanggalnya.
     *
     * @return array<string, string>
     */
    public function tenggatYangLewat(DateTimeImmutable $hariIni): array
    {
        $lewat = [];

        foreach ($this->daftar as $nama => $entri) {
            if ($entri['tenggat'] < $hariIni->format('Y-m-d')) {
                $lewat[$nama] = $entri['tenggat'];
            }
        }

        return $lewat;
    }
}
