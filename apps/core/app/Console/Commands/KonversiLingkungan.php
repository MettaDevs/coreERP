<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MemegangOperasiLingkungan;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Mengubah lingkungan demo menjadi produksi, di tempat, beserta seluruh isinya.
 *
 * ## Kenapa di tempat, dan bukan lingkungan baru yang kosong
 *
 * Alasannya bukan kerapian melainkan penjualan: **data yang diisi prospek selama demo adalah alasan
 * terkuat ia jadi membeli.** Membuangnya lalu menyuruhnya mengetik ulang adalah cara termurah
 * kehilangan pelanggan yang sudah hampir menandatangani. Power Platform melakukannya persis begitu
 * — trial diubah menjadi produksi dengan memindahkan kapasitasnya, bukan dengan menggantinya.
 *
 * Dan ia **operasi eksplisit yang dijalankan operator**, bukan akibat sebuah peristiwa. Business
 * Central menutup masa trialnya berdasarkan siapa yang pertama kali masuk sesudah lisensi dipasang;
 * pemicu yang bergantung pada urutan login adalah pemicu yang akan salah, dan ia sengaja tidak
 * ditiru di sini.
 *
 * ## Yang dihalangi registry, dan itu inti pekerjaan perintah ini
 *
 * Konversi yang naif — satu `UPDATE kind = 'production'` — ditolak database, dan tiap penolakannya
 * menunjuk sesuatu yang memang tidak boleh terjadi:
 *
 * - `environments_keluar_ikut_jenis` mengikat `outbound_allowed` pada `kind = 'production'`. Jadi
 *   jenis dan bendera sambungan keluar **wajib berubah dalam satu pernyataan**; mengubah satu lalu
 *   satunya lagi ditolak pada pernyataan pertama. Itu juga yang membuat "produksi yang webhook-nya
 *   masih mati" tidak dapat diwakili sama sekali.
 * - `environments_satu_produksi` mengizinkan tepat satu produksi hidup per tenant. Tenant yang
 *   sudah punya produksi karena itu **tidak boleh** mengkonversi demonya, dan penolakannya lahir
 *   dua kali: sebagai pemeriksaan yang memberi kalimat terbaca, dan sebagai partial unique index
 *   yang menangkap dua konversi yang berlomba.
 * - `environments_demo_berakhir` mewajibkan demo punya tanggal berakhir. Produksi tidak, dan
 *   tanggal itu **harus dilepas** — bukan karena constraint-nya menuntut, melainkan karena sapuan
 *   kedaluwarsa kelak membaca kolom itu tanpa melihat jenisnya, dan pelanggan yang baru saja
 *   membayar adalah korban yang paling mahal.
 * - `environments_sumber_hanya_sandbox` diperiksa dan ternyata **tidak menggigit**: ia melarang
 *   baris non-sandbox membawa `source_environment_id`, sehingga sebuah demo tidak pernah bisa lahir
 *   dengan kolom itu terisi. Yang dikonversi karena itu selalu membawanya kosong.
 *
 * ## Yang tertahan selama demo, lalu terbit begitu bendera menyala
 *
 * Ada satu, dan hanya satu. Tiga titik di repo ini bertanya `LingkunganAktif::bolehKeluar()`:
 * penerbit event workflow, pengirim laporan kesalahan ke Discord, dan jaring HTTP global. Dua yang
 * terakhir tidak menumpuk apa pun — laporan yang ditekan hilang saat itu juga, dan panggilan yang
 * ditolak melempar di tempat. Yang pertama menumpuk: `PublishWorkflowEvents` memilih barisnya
 * dengan `published_at IS NULL` **tanpa batas umur sama sekali**.
 *
 * Jadi `outbox_events` milik sebuah demo adalah antrean, bukan riwayat. Selama demo ia tidak
 * terkirim; begitu `outbound_allowed` menyala, seluruh isinya menjadi layak kirim — berbulan-bulan
 * keputusan yang dibuat prospek selagi mencoba-coba, berangkat ke endpoint sungguhan milik app yang
 * berjalan sebagai proses tersendiri, dalam hitungan menit sesudah konversi dan tanpa ada yang
 * menekan tombol apa pun.
 *
 * Karena itu konversi **melucuti antrean itu lebih dulu**, dan urutannya mengikat: lucuti, baru
 * nyalakan. Kebalikannya menyisakan jendela tempat proses yang mati meninggalkan antrean yang sudah
 * hidup dan tidak akan pernah dibersihkan siapa pun — jalur pelucutan milik `PublishWorkflowEvents`
 * hanya berjalan ketika `bolehKeluar()` **salah**, sehingga sesudah bendera menyala tidak ada lagi
 * kesempatan kedua.
 *
 * Ditandai terbit, bukan dihapus. Itu pilihan yang sama dengan yang sudah diambil jalur pelucutan
 * di `PublishWorkflowEvents`, dan alasannya sama: baris yang tidak pernah ditandai akan diambil
 * ulang setiap menit selamanya, dan antrean yang tidak pernah menyusut menyembunyikan baris yang
 * benar-benar gagal terkirim. Penghapusan fisik juga dilarang repo ini.
 *
 * Yang dilucuti **seluruh jenis event**, bukan hanya dua jenis yang penerbit hari ini kenal. Yang
 * berbahaya bukan "jenis yang ada penerbitnya sekarang" melainkan "baris yang ditulis selagi
 * menghubungi luar dilarang". Jenis ketiga yang lahir bulan depan akan memperoleh penerbitnya bulan
 * depan juga, dan penerbit itu akan menemukan tumpukan demo yang persis sama — sementara daftar
 * jenis yang ditulis di sini sudah basi tanpa ada yang tahu. Itu bentuk kegagalan yang sama dengan
 * daftar pelucutan terpusat yang rancangan ini tolak.
 *
 * ## Kenapa gagal TIDAK berarti `degraded`
 *
 * Ini satu-satunya tempat perintah ini sengaja menyimpang dari `environment:siapkan`. Di sana
 * kegagalan meninggalkan tempat yang setengah jadi, jadi menurunkannya ke `degraded` justru
 * melindungi: yang gagal disiapkan memang tidak boleh dimasuki.
 *
 * Di sini kebalikannya. Konversi yang gagal meninggalkan demo yang **masih utuh dan masih
 * dipakai** — prospeknya sedang di dalamnya. Menurunkannya berarti mengusir orang dari lingkungan
 * yang sehat karena sebuah kegagalan pembukuan, tepat pada hari ia memutuskan membeli. Yang
 * ditandai gagal cukup operasinya, beserta langkah terakhir yang tercapai.
 */
final class KonversiLingkungan extends Command
{
    use MemegangOperasiLingkungan;

    protected $signature = 'environment:konversi {environment : Id baris environments yang dikonversi}';

    protected $description = 'Ubah sebuah lingkungan demo menjadi produksi di tempat, beserta datanya';

    /** Koneksi sementara ke database lingkungan yang sedang dikonversi. */
    private const KONEKSI = 'lingkungan_dikonversi';

    /**
     * Berapa lama operasi ini boleh memegang kuncinya sebelum boleh direbut.
     *
     * Lebih pendek daripada `environment:siapkan` dengan sengaja. Yang di sana menjalankan seluruh
     * migration Core ke database kosong; yang di sini hanya memperbarui baris — satu `UPDATE` pada
     * registry, dan sejumlah `UPDATE` berbatas pada antrean event. Tidak ada langkah yang wajar
     * berjalan belasan menit, jadi tenggat yang panjang hanya memperlama pemulihan ketika prosesnya
     * benar-benar mati.
     */
    protected function tenggatOperasiMenit(): int
    {
        return 15;
    }

    /**
     * Berapa baris antrean diangkat sekali jalan.
     *
     * Antrean sebuah demo yang berumur berbulan-bulan bisa besar, dan satu `UPDATE` atas
     * seluruhnya adalah satu transaksi raksasa yang menahan baris-barisnya selama ia berjalan.
     * Dipotong supaya konversi tidak pernah menjadi operasi yang mengunci antrean orang.
     */
    private const SEKALI_ANGKUT = 1000;

    public function handle(): int
    {
        $id = (string) $this->argument('environment');
        $lingkungan = Environment::query()->find($id);

        if (! $lingkungan instanceof Environment) {
            $this->error(sprintf('Environment "%s" tidak ada di registry.', $id));

            return self::FAILURE;
        }

        // Aman dijalankan ulang, dan ia berdiri paling depan.
        //
        // Yang dituju bukan kenyamanan melainkan bentuk pemulihan yang seluruh rancangan ini
        // izinkan: perbaikan maju, dijalankan ulang seutuhnya. Sebuah konversi yang mati sesudah
        // barisnya naik tetapi sebelum operasinya ditutup harus boleh dijalankan lagi tanpa
        // menghasilkan galat yang menyuruh orang menebak apakah pekerjaannya sudah selesai.
        //
        // Tidak ada operasi yang dibuka di sini. "Tidak mengubah apa pun" termasuk tidak menambah
        // baris riwayat — riwayat yang penuh operasi yang tidak mengerjakan apa-apa adalah riwayat
        // yang berhenti dibaca orang.
        if ($lingkungan->produksi()) {
            $this->info(sprintf(
                'Environment "%s" sudah berjenis produksi; tidak ada yang diubah.',
                $lingkungan->slug,
            ));

            return self::SUCCESS;
        }

        if ($lingkungan->kind !== 'demo') {
            // Sandbox berhenti di sini, dan sebabnya bukan kehati-hatian. Sandbox adalah salinan
            // sebuah produksi; mempromosikannya menghasilkan dua tempat berisi data yang sama,
            // keduanya mengaku produksi, keduanya boleh menghubungi pihak luar dengan nomor
            // dokumen yang sama persis. Yang menghalanginya sesudah ini `environments_satu_produksi`
            // — tetapi hanya selama sumbernya masih hidup, dan itu bukan jaminan yang layak
            // diandalkan.
            $this->error(sprintf(
                'Environment "%s" berjenis %s. Hanya demo yang boleh dikonversi: sandbox adalah '
                .'salinan sebuah produksi, dan mempromosikannya berarti dua tempat berisi data yang '
                .'sama sama-sama mengaku produksi.',
                $lingkungan->slug,
                $lingkungan->kind,
            ));

            return self::FAILURE;
        }

        if ($lingkungan->status !== 'active') {
            $this->error(sprintf(
                'Environment "%s" berstatus %s. Hanya yang aktif yang boleh dikonversi — lingkungan '
                .'yang belum selesai disiapkan tidak punya apa pun untuk dipromosikan. Jalankan '
                .'`environment:siapkan %s` lebih dulu.',
                $lingkungan->slug,
                $lingkungan->status,
                $lingkungan->id,
            ));

            return self::FAILURE;
        }

        $produksi = $this->produksiLain($lingkungan);

        if ($produksi instanceof Environment) {
            $this->error(sprintf(
                'Tenant ini sudah punya produksi hidup: "%s". Satu tenant hanya mengenal satu '
                .'produksi, jadi demo "%s" tidak dapat menjadi yang kedua. Yang harus diputuskan '
                .'lebih dulu adalah nasib produksi yang sudah ada — dipindahkan datanya, atau '
                .'dihapus lunak — dan itu keputusan orang, bukan perintah ini.',
                $produksi->slug,
                $lingkungan->slug,
            ));

            return self::FAILURE;
        }

        $operasi = $this->bukaOperasi($lingkungan, 'convert');

        if (! $operasi instanceof EnvironmentOperation) {
            return self::FAILURE;
        }

        $berakhir = $lingkungan->expires_at;
        $langkah = 'lucuti-antrean';

        try {
            $dilucuti = $this->lucutiAntreanEvent($lingkungan);
            $this->line(sprintf('  %d event yang belum terbit ditandai terbit tanpa dikirim.', $dilucuti));

            $langkah = 'jadikan-produksi';
            $this->jadikanProduksi($lingkungan);

            $operasi->update([
                'status' => 'succeeded',
                'step' => $langkah,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => [
                    'jenis_sebelumnya' => 'demo',
                    'event_dilucuti' => $dilucuti,
                    'berakhir_dilepas' => self::waktu($berakhir),
                ],
            ]);

            $this->info(sprintf(
                'Environment "%s" kini produksi. Sambungan keluar menyala dan tanggal berakhirnya dilepas.',
                $lingkungan->slug,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->tutupOperasiSebagaiGagal($operasi, $langkah, $e);

            $this->error(sprintf('Konversi berhenti di langkah "%s": %s', $langkah, $e->getMessage()));
            $this->line(
                'Lingkungannya dibiarkan sebagaimana adanya — ia masih demo yang sehat dan masih '
                .'dapat dipakai. Perbaiki sebabnya lalu jalankan perintah yang sama sekali lagi.'
            );

            return self::FAILURE;
        } finally {
            DB::purge(self::KONEKSI);
        }
    }

    /**
     * Menuliskan sebuah nilai waktu sebagai teks ISO yang bentuknya tidak berubah-ubah.
     *
     * Kolomnya sendiri sudah di-cast `datetime`, jadi yang datang ke sini selalu `Carbon` saat
     * berjalan. Bentuk mentahnya tetap dilayani karena cast itu dideklarasikan lewat method
     * `casts()`, yang tidak terbaca analisa statis — dan sebuah cabang yang hanya ada demi analisa
     * statis lebih baik jujur mengerjakan sesuatu daripada dituliskan sebagai pengecualian.
     *
     * Menyerahkannya begitu saja ke `json_encode` bukan pilihan: bentuk JSON milik Carbon
     * bergantung pada `Carbon::serializeUsing`, yaitu setelan global yang boleh diubah paket mana
     * pun. Nilai yang tersimpan di riwayat tidak boleh berubah bentuk karena setelan di tempat
     * lain.
     */
    private static function waktu(mixed $nilai): ?string
    {
        if ($nilai instanceof CarbonInterface) {
            return $nilai->toIso8601String();
        }

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }

    /**
     * Produksi lain milik tenant yang sama, kalau ada.
     *
     * `deleted_at` ikut disaring supaya sama persis dengan partial unique index yang menegakkannya:
     * pemeriksaan yang lebih ketat daripada indeksnya akan menolak konversi yang sebenarnya sah —
     * tenant yang produksinya sudah dihapus lunak memang boleh punya produksi baru — dan perbedaan
     * seperti itu muncul sebagai penolakan yang tidak dapat dijelaskan siapa pun.
     */
    private function produksiLain(Environment $lingkungan): ?Environment
    {
        return Environment::query()
            ->where('tenant_id', $lingkungan->tenant_id)
            ->where('kind', 'production')
            ->whereNull('deleted_at')
            ->where('id', '!=', $lingkungan->id)
            ->first();
    }

    /**
     * Menaikkan jenisnya menjadi produksi — jenis, bendera keluar, dan tanggal berakhir sekaligus.
     *
     * Ketiganya dalam **satu** `UPDATE`, dan itu syarat bukan gaya.
     * `environments_keluar_ikut_jenis` diperiksa PostgreSQL per baris atas nilai barunya, jadi
     * memecahnya menjadi dua pernyataan berarti pernyataan pertama sudah ditolak — apa pun
     * urutannya.
     */
    private function jadikanProduksi(Environment $lingkungan): void
    {
        $koneksi = DB::connection($lingkungan->getConnectionName());

        try {
            // Savepoint. PostgreSQL membatalkan seluruh blok transaksi begitu satu pernyataan di
            // dalamnya ditolak, dan pernyataan ini memang boleh ditolak — dua konversi yang
            // berlomba diselesaikan oleh partial unique index, bukan oleh pemeriksaan di atas.
            // Tanpa savepoint, penolakan yang sudah diperhitungkan ini menjatuhkan transaksi milik
            // siapa pun yang kebetulan membungkus perintah ini.
            $koneksi->transaction(function () use ($lingkungan): void {
                $lingkungan->update([
                    'kind' => 'production',
                    'outbound_allowed' => true,
                    'expires_at' => null,
                ]);
            });
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'environments_satu_produksi')) {
                throw $e;
            }

            throw new RuntimeException(
                'Tenant ini memperoleh produksi di sela pemeriksaan dan penulisan — konversi lain '
                .'menang lebih dulu. Periksa daftar lingkungannya sebelum mencoba lagi.',
                previous: $e,
            );
        }
    }

    /**
     * Menandai seluruh event yang belum terbit sebagai terbit, tanpa mengirim satu pun.
     *
     * Alasannya panjang dan ada di docblock kelas. Yang perlu diingat saat membaca method ini:
     * ini satu-satunya kesempatan. Sesudah `outbound_allowed` menyala, jalur pelucutan milik
     * `PublishWorkflowEvents` tidak pernah berjalan lagi untuk lingkungan ini.
     *
     * Disaring `tenant_id`, dan itu wajib. Sebuah lingkungan yang `database_name`-nya kosong ikut
     * database koneksi bawaan — keadaan pooled dan on-prem — dan di sana `outbox_events` memuat
     * baris milik tenant lain yang sama sekali tidak sedang dikonversi. Melucutinya berarti
     * membatalkan pengiriman event pelanggan yang tidak melakukan apa-apa.
     */
    private function lucutiAntreanEvent(Environment $lingkungan): int
    {
        $koneksi = $this->koneksiLingkungan($lingkungan);
        $jumlah = 0;

        while (true) {
            $id = $koneksi->table('outbox_events')
                ->where('tenant_id', $lingkungan->tenant_id)
                ->whereNull('published_at')
                ->orderBy('occurred_at')
                ->limit(self::SEKALI_ANGKUT)
                ->pluck('id')
                ->all();

            if ($id === []) {
                return $jumlah;
            }

            // `whereNull` diulang pada penulisannya, bukan hanya pada pemilihannya: di keadaan
            // pooled sebuah penerbit yang sedang berjalan boleh saja menerbitkan baris yang sama
            // di sela keduanya, dan menimpa `published_at` miliknya akan memalsukan waktu terbit
            // sebuah event yang benar-benar terkirim.
            $jumlah += $koneksi->table('outbox_events')
                ->whereIn('id', $id)
                ->whereNull('published_at')
                ->update(['published_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Koneksi ke database tempat data lingkungan ini benar-benar hidup.
     *
     * `database_name` yang kosong berarti "ikut koneksi bawaan", dan di situ ia **harus** memakai
     * koneksi bawaan itu sendiri — bukan salinan konfigurasinya. PDO kedua ke database yang sama
     * membuka transaksi sendiri, dan apa yang ditulis satu koneksi tidak terlihat oleh yang lain.
     * Di suite test, tempat `RefreshDatabase` memegang transaksi pada koneksi bawaan, salinan
     * seperti itu akan menemukan antrean yang kosong lalu melaporkan berhasil — penjaga yang hijau
     * karena buta, bentuk kegagalan yang sudah dua kali membakar repo ini.
     */
    private function koneksiLingkungan(Environment $lingkungan): ConnectionInterface
    {
        $nama = $lingkungan->database_name;

        if ($nama === null) {
            return DB::connection();
        }

        $bawaan = (string) config('database.default');
        $konfigurasi = config('database.connections.'.$bawaan);

        if (! is_array($konfigurasi)) {
            throw new RuntimeException(sprintf('Koneksi bawaan "%s" tidak terbaca dari config.', $bawaan));
        }

        $konfigurasi['database'] = $nama;
        // Laravel mendahulukan `url` di atas `database` bila ia terisi. Dibiarkan, seluruh
        // pelucutan di atas akan berjalan pada database pusat.
        $konfigurasi['url'] = null;

        config(['database.connections.'.self::KONEKSI => $konfigurasi]);
        DB::purge(self::KONEKSI);

        return DB::connection(self::KONEKSI);
    }
}
