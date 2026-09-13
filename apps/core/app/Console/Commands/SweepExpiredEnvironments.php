<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HoldsEnvironmentOperation;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Menutup lingkungan demo yang masa berlakunya sudah lewat — dihapus lunak, belum dibuang.
 *
 * Ia pasangan dari `environments_demo_berakhir`. Constraint itu mewajibkan setiap demo punya
 * tanggal berakhir justru supaya ada yang dapat menyapunya; tanpa penyapunya, kolom itu hanya
 * catatan yang tidak pernah dibaca siapa pun dan disknya tetap penuh.
 *
 * ## Kenapa hapus lunak, dan kenapa ketiga kolomnya sekaligus
 *
 * Registry ini mengikat tiga kolom menjadi satu keputusan, dan keduanya ditegakkan PostgreSQL:
 *
 * - `environments_hapus_berpasangan` — `deleted_at` dan `purge_after` selalu berpasangan. Jadi
 *   "dihapus tanpa jadwal" tidak dapat diwakili sama sekali, dan itu memang benar: menghapus tanpa
 *   memutuskan kapan isinya boleh hilang berarti menyimpannya selamanya tanpa ada yang pernah
 *   memutuskannya.
 * - `environments_status_hapus_sejalan` — `status = 'soft_deleted'` persis ketika `deleted_at`
 *   terisi. Jadi status dan tanggalnya tidak dapat berbeda pendapat.
 *
 * Akibatnya bagi perintah ini mengikat: ketiganya **wajib berubah dalam satu `UPDATE`**. Memecahnya
 * menjadi dua pernyataan berarti pernyataan pertama sudah ditolak, apa pun urutannya — persis
 * seperti `environment:convert` yang wajib menaikkan jenis dan bendera keluar sekaligus.
 *
 * ## Kenapa ia melanjutkan sesudah gagal
 *
 * Ini sifat yang paling penting di kelas ini, dan yang paling mudah hilang saat seseorang
 * merapikannya menjadi satu query.
 *
 * Loop naif yang melempar pada lingkungan ketiga membuat lingkungan keempat sampai kedua-ratus
 * tidak pernah tersapu. Penjadwal membuang keluaran perintahnya, jadi tidak ada satu pun yang
 * berbunyi — sampai disknya penuh, berbulan-bulan kemudian, dan yang terlihat saat itu hanyalah
 * server yang kehabisan tempat tanpa sebab yang jelas.
 *
 * Karena itu tiap lingkungan berdiri sendiri: kunci sendiri, baris riwayat sendiri, dan kegagalan
 * yang berhenti pada dirinya sendiri. Tiga hal ikut dari sana:
 *
 * 1. **Tulisannya dibungkus transaksi bersarang.** PostgreSQL membatalkan seluruh blok transaksi
 *    begitu satu pernyataan ditolak; tanpa savepoint, kegagalan pada lingkungan ketiga menjatuhkan
 *    setiap pernyataan sesudahnya dengan `25P02` — gagal karena percobaan sebelumnya, bukan karena
 *    dirinya. "Melanjutkan" akan berubah menjadi "melanjutkan lalu gagal semuanya".
 * 2. **Tiap hasil dicatat di `environment_operations`.** Itu jejak yang tidak ikut hilang bersama
 *    keluaran konsol yang dibuang penjadwal, dan ia yang menjawab kenapa sebuah lingkungan
 *    tertinggal.
 * 3. **Kegagalan juga ditulis ke log aplikasi**, karena lingkungan yang kuncinya sedang dipegang
 *    orang lain tidak pernah sempat punya baris riwayat sendiri — dan itu justru bentuk kegagalan
 *    yang paling mungkin terjadi.
 *
 * Kode keluarnya tetap merah bila ada satu saja yang gagal. Melanjutkan bukan berarti berpura-pura
 * berhasil.
 *
 * ## Kenapa operasinya bernama `expire`
 *
 * `soft_delete` juga tersedia di daftar jenis operasi, dan ia dibiarkan untuk penghapusan yang
 * benar-benar diputuskan seseorang. Yang ini tidak diputuskan siapa pun pada saat ia terjadi —
 * yang menentukannya tanggal yang sudah disepakati jauh hari. Nama yang menyebut sebabnya membuat
 * riwayatnya dapat dibaca tanpa membuka kode, dan `environment:restore` menerima keduanya.
 */
final class SweepExpiredEnvironments extends Command
{
    use HoldsEnvironmentOperation;

    protected $signature = 'environment:sweep-expired
        {--grace-days= : Berapa hari isinya masih disimpan sebelum boleh dibuang permanen}';

    protected $description = 'Hapus lunak setiap lingkungan demo yang masa berlakunya sudah lewat';

    /**
     * Berapa lama isi sebuah demo yang kedaluwarsa masih disimpan sebelum boleh dibuang permanen.
     *
     * Tiga puluh hari, dan angkanya diturunkan dari apa yang sebenarnya terjadi ketika sebuah demo
     * kedaluwarsa: hampir selalu percakapan penjualannya sedang menggantung, bukan sudah selesai.
     * Masa tenggang yang lebih pendek daripada satu siklus keputusan pelanggan berarti data yang
     * justru menjadi alasan terkuat ia membeli — yang diketiknya sendiri selama mencoba — sudah
     * hilang tepat pada hari ia memutuskan.
     *
     * Batas atasnya disk, dan itu sebabnya ia tidak dibuat setengah tahun. Tiga puluh hari memberi
     * satu siklus keputusan penuh dan tetap menjadikan disk fungsi dari jumlah pelanggan aktif,
     * bukan dari jumlah pelanggan yang pernah mencoba.
     */
    public const GRACE_DAYS = 30;

    /**
     * Tenggat kuncinya, dan ia pendek dengan sengaja.
     *
     * Yang dikerjakan per lingkungan hanya satu `UPDATE` pada satu baris registry — tidak ada
     * database yang dibuat, tidak ada migration, tidak ada yang disalin. Operasi sapuan yang
     * prosesnya mati karena itu tidak layak menahan lingkungannya sampai sapuan besok; lima menit
     * sudah jauh lebih panjang daripada apa pun yang wajar terjadi di sini.
     */
    protected function operationLeaseMinutes(): int
    {
        return 5;
    }

    public function handle(): int
    {
        $graceDays = $this->graceDays();

        if ($graceDays === null) {
            return self::FAILURE;
        }

        // Diambil sekaligus, bukan lewat cursor. Hasil query yang masih terbuka dan penulisan pada
        // koneksi yang sama adalah kombinasi yang tidak layak dipertaruhkan pada perintah yang
        // justru ada untuk menulis ke setiap baris yang dibacanya.
        //
        // `deleted_at` ikut disaring, kalau tidak sapuan besok akan mencoba menghapus lunak
        // lingkungan yang sudah dihapus lunak hari ini — dan gagal, setiap hari, selamanya.
        $list = Environment::query()
            ->where('kind', 'demo')
            ->whereNull('deleted_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->get();

        if ($list->isEmpty()) {
            $this->info('Tidak ada lingkungan demo yang masa berlakunya sudah lewat.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d lingkungan demo kedaluwarsa; masa tenggangnya %d hari.', $list->count(), $graceDays));

        $succeeded = 0;
        $failed = 0;

        // Status tidak ikut disaring, dan itu disengaja. Demo yang kedaluwarsa tetap kedaluwarsa
        // apa pun keadaannya — yang setengah jadi, yang degraded, maupun yang ditangguhkan. Satu
        // keadaan yang memang tidak boleh disentuh adalah lingkungan yang sedang dikerjakan operasi
        // lain, dan itu sudah dijaga kuncinya, bukan oleh daftar status yang harus dirawat tangan.
        foreach ($list as $environment) {
            if ($this->sweepOne($environment, $graceDays)) {
                $succeeded++;

                continue;
            }

            $failed++;
        }

        $this->info(sprintf('Sapuan selesai: %d tersapu, %d gagal.', $succeeded, $failed));

        if ($failed > 0) {
            // Merah, meski sebagian besar berhasil. Sapuan yang melaporkan sukses sambil
            // meninggalkan lingkungan yang tidak tersapu adalah sapuan yang tidak pernah diperiksa
            // siapa pun sampai disknya penuh.
            $this->error(sprintf(
                '%d lingkungan tidak tersapu. Sebabnya ada di `environment_operations` dan di log '
                .'aplikasi; jalankan perintah yang sama lagi setelah sebabnya dibereskan.',
                $failed,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Menyapu satu lingkungan, dan menelan kegagalannya supaya sisanya tetap jalan.
     *
     * Mengembalikan false, bukan melempar. Itu bentuk yang membuat pemanggilnya tidak punya cara
     * untuk berhenti di tengah tanpa sengaja.
     */
    private function sweepOne(Environment $environment, int $graceDays): bool
    {
        $operation = $this->openOperation($environment, 'expire');

        if (! $operation instanceof EnvironmentOperation) {
            // Kuncinya sedang dipegang operasi lain yang tenggatnya belum lewat. Ini kegagalan yang
            // paling mungkin terjadi dan satu-satunya yang tidak meninggalkan baris riwayat sendiri
            // — jadi log aplikasi satu-satunya tempat ia tercatat.
            Log::warning('Lingkungan kedaluwarsa dilewati karena operasi lain sedang berjalan.', [
                'environment' => $environment->id,
                'slug' => $environment->slug,
            ]);

            return false;
        }

        $previous = $environment->status;
        $expired = self::asTime($environment->expires_at);
        $step = 'hapus-lunak';

        try {
            $now = now();
            $purgeAfter = $now->copy()->addDays($graceDays);

            $this->softDelete($environment, $now, $purgeAfter);

            $operation->update([
                'status' => 'succeeded',
                'step' => $step,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => [
                    // Status sebelumnya disimpan di sini karena barisnya sendiri sudah tidak dapat
                    // menyimpannya: `environments_status_hapus_sejalan` memaksa kolom status
                    // menjadi `soft_deleted`. Tanpa catatan ini, `environment:restore` harus
                    // menebak keadaan apa yang dikembalikannya — dan menebak `active` untuk
                    // lingkungan yang sebenarnya degraded berarti mengembalikannya sebagai tempat
                    // yang boleh dimasuki padahal ia tidak pernah selesai disiapkan.
                    'status_sebelumnya' => $previous,
                    'kedaluwarsa_pada' => $expired?->toIso8601String(),
                    'boleh_dibuang_pada' => $purgeAfter->toIso8601String(),
                ],
            ]);

            $this->line(sprintf(
                '  "%s" dihapus lunak; isinya masih dapat dipulihkan sampai %s.',
                $environment->slug,
                $purgeAfter->toDateTimeString(),
            ));

            return true;
        } catch (Throwable $e) {
            $this->closeOperationAsFailed($operation, $step, $e);

            // Dua tempat, dan keduanya perlu. Riwayat operasinya yang dibaca operator saat
            // menelusuri satu lingkungan; log aplikasi yang dibaca ketika yang dicari justru
            // "apakah sapuan tadi malam berjalan mulus".
            Log::error('Sapuan lingkungan kedaluwarsa gagal pada satu lingkungan.', [
                'environment' => $environment->id,
                'slug' => $environment->slug,
                'langkah' => $step,
                'sebab' => $e->getMessage(),
            ]);

            $this->error(sprintf('  "%s" gagal disapu di langkah "%s": %s', $environment->slug, $step, $e->getMessage()));

            return false;
        }
    }

    /**
     * Menulis ketiga kolom penghapusan sekaligus, dalam satu pernyataan.
     *
     * `deleted_at` dan `purge_after` tidak ada di `$fillable` milik model, dan itu bukan kelalaian:
     * keduanya terikat constraint dan tidak pernah boleh ditulis satu-satu. Jadi jalurnya query
     * builder, bukan `$model->update()` — bentuk yang memang hanya sanggup menulis ketiganya
     * bersamaan.
     *
     * Dibungkus transaksi bersarang — SAVEPOINT di PostgreSQL. Penolakan di sini memang mungkin dan
     * sudah diperhitungkan, dan tanpa savepoint ia akan menjatuhkan seluruh blok transaksi milik
     * siapa pun yang membungkus perintah ini, termasuk sisa sapuan ini sendiri.
     *
     * `whereNull('deleted_at')` diulang pada penulisannya, bukan hanya pada pemilihannya: antara
     * daftar dibaca dan baris ini ditulis, seseorang di layar operator boleh saja sudah
     * menghapusnya lebih dulu. Menimpanya berarti memundurkan masa tenggang yang sudah berjalan.
     */
    private function softDelete(Environment $environment, CarbonInterface $now, CarbonInterface $purgeAfter): void
    {
        $koneksi = DB::connection($environment->getConnectionName());

        $affected = (int) $koneksi->transaction(fn (): int => Environment::query()
            ->whereKey($environment->id)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'soft_deleted',
                'deleted_at' => $now,
                'purge_after' => $purgeAfter,
            ]));

        if ($affected !== 1) {
            throw new RuntimeException(
                'Baris registrinya sudah dihapus lunak jalur lain di sela daftar dibaca dan baris '
                .'ini ditulis. Tidak ada yang diubah; masa tenggang yang sudah berjalan dibiarkan.'
            );
        }

        $environment->refresh();
    }

    /**
     * Masa tenggang yang dipakai sapuan ini, dari opsi bila ada.
     *
     * Nol tidak diterima. Masa tenggang nol hari berarti sapuan dan penghapusan permanen dapat
     * berjalan pada hari yang sama, yaitu menghapus jendela yang menjadi satu-satunya alasan hapus
     * lunak ada. Salah ketik satu karakter tidak layak menjadi jalan menuju ke sana.
     */
    private function graceDays(): ?int
    {
        $value = $this->option('grace-days');

        if ($value === null || $value === '') {
            return self::GRACE_DAYS;
        }

        if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
            $this->error(sprintf('Masa tenggang "%s" tidak dikenali. Isi jumlah hari, minimal 1.', $value));

            return null;
        }

        return (int) $value;
    }

    /**
     * Membaca sebuah kolom waktu milik `Environment` sebagai waktu, apa pun yang dilihat analisa.
     *
     * Cast `datetime` pada model itu dideklarasikan lewat method `casts()`, dan bentuk itu tidak
     * terbaca analisa statis — di sana kolomnya tetap terlihat sebagai teks. Yang datang saat
     * berjalan selalu `Carbon`, dan bentuk teksnya tetap dilayani di sini karena kebenaran kode ini
     * tidak layak bergantung pada apa yang kebetulan dilihat sebuah alat.
     */
    private static function asTime(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
