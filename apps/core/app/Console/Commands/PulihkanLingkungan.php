<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MemegangOperasiLingkungan;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Mengembalikan lingkungan yang dihapus lunak, selama isinya memang masih ada.
 *
 * Ia yang membuat hapus lunak berarti sesuatu. Sapuan kedaluwarsa yang tidak punya pasangan
 * pemulihan bukan hapus lunak melainkan hapus keras yang ditunda — dan masa tenggang yang tidak
 * pernah dapat dipakai siapa pun hanyalah ongkos disk tanpa manfaat.
 *
 * ## Tiga kolom dilepas sekaligus, karena memang tidak dapat dilepas satu-satu
 *
 * Bentuknya cermin dari `environment:sapu-kedaluwarsa`, dan constraint yang sama yang
 * menentukannya: `environments_hapus_berpasangan` mengikat `deleted_at` dengan `purge_after`, dan
 * `environments_status_hapus_sejalan` mengikat keduanya dengan `status`. Mengosongkan `deleted_at`
 * saja ditolak pada pernyataan pertama.
 *
 * Jadi status **harus** ditentukan di sini. Bukan sebagai tambahan, melainkan sebagai syarat:
 * melepas penghapusan tanpa menyebutkan status penggantinya adalah keadaan yang tidak dapat
 * diwakili sama sekali.
 *
 * ## Status mana yang dikembalikan, dan kenapa bukan `active` begitu saja
 *
 * `active` adalah jawaban yang menggoda dan salah pada kasus yang justru paling perlu dijaga.
 * Sapuan tidak menyaring status — demo yang kedaluwarsa tetap disapu meski ia `degraded` atau
 * belum pernah selesai disiapkan. Memulihkan semuanya menjadi `active` berarti mengangkat
 * lingkungan yang tidak pernah punya database menjadi tempat yang boleh dirutekan, dan kegagalannya
 * baru muncul di depan penggunanya: halaman yang terbuka lalu mati dengan `database "..." does not
 * exist`.
 *
 * Yang dikembalikan karena itu **keadaan yang benar-benar dimilikinya sebelum disapu**, dibaca dari
 * `environment_operations.detail` milik sapuan yang menghapusnya. Riwayat itu memang ada untuk
 * dibaca; ini pembacanya.
 *
 * Dan ketika riwayat itu tidak ada — lingkungan yang dihapus lunak sebelum perintah ini lahir,
 * atau lewat jalur yang tidak mencatat apa pun — jawabannya `degraded`. Itu bukan hukuman melainkan
 * keadaan yang paling jujur untuk "isinya ada, tetapi tidak ada yang tahu ia sehat": ia tidak dapat
 * dirutekan, dan `environment:siapkan` menerimanya apa adanya sehingga jalan keluarnya satu
 * perintah. Menebak `active` menukar kejujuran itu dengan kegagalan yang muncul di tempat yang
 * jauh lebih mahal.
 *
 * ## Kenapa masa berlakunya ikut diperpanjang
 *
 * Karena tanpa itu pemulihannya dibatalkan penjadwal, dalam hitungan jam.
 *
 * Yang dipulihkan selalu demo yang tanggal berakhirnya sudah lewat — itu sebabnya ia disapu. Baris
 * yang kembali hidup dengan `expires_at` di masa lalu akan ditemukan lagi oleh sapuan berikutnya
 * dan dihapus lunak lagi, tanpa ada yang melakukan kesalahan apa pun. Dan mengosongkan kolomnya
 * bukan pilihan: `environments_demo_berakhir` menolak demo tanpa tanggal berakhir, dan ia benar —
 * demo tanpa tanggal berakhir persis bug yang membuat disk penuh tanpa disadari siapa pun.
 *
 * Jadi memulihkan sebuah demo berarti memutuskan sampai kapan ia hidup. Perintah ini memutuskannya
 * terang-terangan, mencatatnya di riwayat, dan menerima `--hari` bagi operator yang tahu angka yang
 * lebih tepat.
 *
 * ## Yang ditolak, dan kenapa penolakannya bukan kehati-hatian berlebih
 *
 * - **Isinya sudah dibuang permanen.** Yang tersisa nisannya. Memulihkannya menghasilkan baris
 *   yang tampil di daftar, terlihat sehat, dan tidak punya satu byte pun data.
 * - **Masa tenggangnya sudah habis.** Datanya mungkin masih ada — penghapusan permanennya berjalan
 *   terjadwal, bukan seketika — tetapi ia sudah masuk antrean pembuangan dan boleh lenyap kapan
 *   saja. Memulihkan di jendela itu berarti balapan dengan sebuah `DROP DATABASE`, dan yang kalah
 *   adalah lingkungan yang tampak pulih lalu hilang isinya beberapa menit kemudian. Penolakan yang
 *   terbaca jauh lebih baik daripada pemulihan yang mungkin.
 */
final class PulihkanLingkungan extends Command
{
    use MemegangOperasiLingkungan;

    protected $signature = 'environment:pulihkan
        {environment : Id baris environments yang dipulihkan}
        {--hari= : Berapa hari lagi demo ini berlaku setelah dipulihkan}';

    protected $description = 'Kembalikan lingkungan yang dihapus lunak, selama masa tenggangnya belum habis';

    /**
     * Masa berlaku baru sebuah demo yang dipulihkan.
     *
     * Dua minggu, dan ia sengaja lebih pendek daripada masa berlaku demo yang baru lahir. Yang
     * dipulihkan adalah demo yang sudah pernah kedaluwarsa sekali; memberinya periode penuh lagi
     * mengubah pemulihan menjadi cara memperpanjang demo tanpa batas tanpa pernah ada yang
     * memutuskannya. Dua minggu cukup untuk menyelesaikan percakapan yang tertunda, dan operator
     * yang memang butuh lebih dapat menyebut angkanya lewat `--hari`.
     */
    public const PERPANJANGAN_HARI = 14;

    /**
     * Status yang boleh dikembalikan apa adanya dari riwayat.
     *
     * `copying` sengaja di luar daftar meski ia status yang sah. Ia keadaan sementara yang hanya
     * benar selagi sebuah penyalinan berjalan, dan penyalinan itu sudah lama mati ketika
     * lingkungannya disapu. Mengembalikannya berarti menghidupkan lingkungan yang menunggu sesuatu
     * yang tidak akan pernah datang, dan tidak ada satu pun perintah yang menerima status itu
     * sebagai titik awal. `degraded` menerima keduanya: ia jujur, dan ia punya jalan keluar.
     */
    private const STATUS_DIPERCAYA = ['provisioning', 'active', 'maintenance', 'degraded', 'suspended'];

    /** Status yang dipakai ketika riwayatnya tidak menyebutkan apa pun yang dapat dipercaya. */
    private const STATUS_TIDAK_DIKETAHUI = 'degraded';

    /**
     * Tenggat kuncinya. Sama pendeknya dengan sapuan, dan karena alasan yang sama: yang dikerjakan
     * satu `UPDATE` pada satu baris registry.
     */
    protected function tenggatOperasiMenit(): int
    {
        return 5;
    }

    public function handle(): int
    {
        $hari = $this->hari();

        if ($hari === null) {
            return self::FAILURE;
        }

        $id = (string) $this->argument('environment');
        $lingkungan = Environment::query()->find($id);

        if (! $lingkungan instanceof Environment) {
            $this->error(sprintf('Environment "%s" tidak ada di registry.', $id));

            return self::FAILURE;
        }

        // Aman dijalankan ulang, dan tanpa meninggalkan jejak. Riwayat yang penuh operasi yang
        // tidak mengerjakan apa-apa adalah riwayat yang berhenti dibaca orang — alasan yang sama
        // dengan yang sudah dipakai `environment:konversi`.
        if ($lingkungan->deleted_at === null) {
            $this->info(sprintf(
                'Environment "%s" tidak sedang dihapus lunak (statusnya %s); tidak ada yang diubah.',
                $lingkungan->slug,
                $lingkungan->status,
            ));

            return self::SUCCESS;
        }

        if ($this->sudahDibuang($lingkungan)) {
            $this->error(sprintf(
                'Environment "%s" sudah dibuang permanen — databasenya tidak ada lagi. Yang tersisa '
                .'hanya barisnya beserta riwayatnya, dan itu memang sengaja tidak ikut dihapus. '
                .'Pelanggan yang membutuhkannya kembali harus memperoleh lingkungan baru.',
                $lingkungan->slug,
            ));

            return self::FAILURE;
        }

        $bolehDibuang = self::waktu($lingkungan->purge_after);

        if ($bolehDibuang !== null && $bolehDibuang->isPast()) {
            $this->error(sprintf(
                'Masa tenggang environment "%s" habis pada %s. Ia sudah masuk antrean pembuangan '
                .'dan databasenya boleh hilang kapan saja, jadi pemulihan di jendela ini adalah '
                .'balapan dengan penghapusan yang sedang berjalan.',
                $lingkungan->slug,
                $bolehDibuang->toDateTimeString(),
            ));

            return self::FAILURE;
        }

        $operasi = $this->bukaOperasi($lingkungan, 'restore');

        if (! $operasi instanceof EnvironmentOperation) {
            return self::FAILURE;
        }

        $langkah = 'baca-riwayat';

        try {
            $status = $this->statusPulih($lingkungan);
            $berakhir = $lingkungan->kind === 'demo' ? now()->addDays($hari) : null;

            $langkah = 'lepas-penghapusan';
            $this->lepaskanPenghapusan($lingkungan, $status, $berakhir);

            $operasi->update([
                'status' => 'succeeded',
                'step' => $langkah,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => [
                    'status_dipulihkan' => $status,
                    'status_ditebak' => $status === self::STATUS_TIDAK_DIKETAHUI,
                    'berakhir_baru' => $berakhir?->toIso8601String(),
                    'boleh_dibuang_sebelumnya' => $bolehDibuang?->toIso8601String(),
                ],
            ]);

            $this->info(sprintf('Environment "%s" dipulihkan dengan status %s.', $lingkungan->slug, $status));

            if ($status === self::STATUS_TIDAK_DIKETAHUI) {
                $this->warn(sprintf(
                    '  Riwayatnya tidak menyebut keadaan sebelum ia dihapus, jadi statusnya %s — '
                    .'belum dapat dirutekan. Jalankan `environment:siapkan %s` untuk memastikan '
                    .'isinya lengkap.',
                    self::STATUS_TIDAK_DIKETAHUI,
                    $lingkungan->id,
                ));
            }

            if ($berakhir !== null) {
                $this->line(sprintf('  Masa berlakunya kini sampai %s.', $berakhir->toDateTimeString()));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->tutupOperasiSebagaiGagal($operasi, $langkah, $e);

            $this->error(sprintf('Pemulihan berhenti di langkah "%s": %s', $langkah, $e->getMessage()));
            $this->line('Lingkungannya tetap dihapus lunak. Perbaiki sebabnya lalu jalankan perintah yang sama sekali lagi.');

            return self::FAILURE;
        }
    }

    /**
     * Apakah isinya sudah benar-benar dibuang.
     *
     * Dibaca lewat query, bukan lewat properti modelnya. `purged_at` menyusul jauh sesudah model
     * ini ditulis, dan menambahkannya ke sana adalah perubahan pada berkas yang sedang dipegang
     * pekerjaan lain — jadi ia dibaca di tempat yang memang miliknya. Artinya juga tidak berubah
     * karena itu: terisi berarti databasenya sudah tidak ada di mana pun.
     */
    private function sudahDibuang(Environment $lingkungan): bool
    {
        return Environment::query()
            ->whereKey($lingkungan->id)
            ->whereNotNull('purged_at')
            ->exists();
    }

    /**
     * Status yang dimilikinya sebelum ia dihapus lunak, dibaca dari riwayatnya sendiri.
     *
     * Dua jenis operasi diterima. `expire` ditulis sapuan kedaluwarsa, `soft_delete` disediakan
     * untuk penghapusan yang benar-benar diputuskan seseorang — bentuk barisnya sama, jadi
     * pembacanya tidak perlu tahu mana yang menghapusnya.
     *
     * Yang terbaru yang menang. Sebuah lingkungan boleh dihapus dan dipulihkan berkali-kali, dan
     * yang berlaku selalu keadaan sesaat sebelum penghapusan yang terakhir.
     */
    private function statusPulih(Environment $lingkungan): string
    {
        $jejak = EnvironmentOperation::query()
            ->where('environment_id', $lingkungan->id)
            ->whereIn('operation', ['expire', 'soft_delete'])
            ->where('status', 'succeeded')
            ->orderByDesc('started_at')
            ->first();

        $sebelumnya = self::statusDalam($jejak?->detail);

        if ($sebelumnya !== null && in_array($sebelumnya, self::STATUS_DIPERCAYA, true)) {
            return $sebelumnya;
        }

        return self::STATUS_TIDAK_DIKETAHUI;
    }

    /**
     * Menggali status sebelumnya dari kolom `detail` sebuah baris riwayat.
     *
     * Bentuk teks ikut dilayani karena cast `array` pada `EnvironmentOperation` dideklarasikan
     * lewat method `casts()`, dan bentuk itu tidak terbaca analisa statis — yang berarti tidak ada
     * yang menjamin setiap pembaca kelak menerimanya sudah terurai. Isi kolomnya juga ditulis
     * perintah lain di masa lalu: apa pun yang tidak berbentuk seperti yang diharapkan dijawab
     * null, bukan dipaksakan.
     */
    private static function statusDalam(mixed $detail): ?string
    {
        if (is_string($detail)) {
            $detail = json_decode($detail, true);
        }

        if (! is_array($detail)) {
            return null;
        }

        $status = $detail['status_sebelumnya'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * Membaca sebuah kolom waktu milik `Environment` sebagai waktu, apa pun yang dilihat analisa.
     *
     * Alasannya sama dengan yang tertulis di `SapuLingkunganKedaluwarsa`: cast `datetime` milik
     * model itu dideklarasikan lewat method `casts()`, dan analisa statis tetap melihatnya sebagai
     * teks. Yang datang saat berjalan selalu `Carbon`.
     */
    private static function waktu(mixed $nilai): ?CarbonInterface
    {
        if ($nilai instanceof CarbonInterface) {
            return $nilai;
        }

        return is_string($nilai) && $nilai !== '' ? Carbon::parse($nilai) : null;
    }

    /**
     * Melepas ketiga kolom penghapusan — dan masa berlaku barunya — dalam satu pernyataan.
     *
     * Dibungkus transaksi bersarang dengan alasan yang sama seperti di sapuan: penolakan di sini
     * memang mungkin, dan tanpa SAVEPOINT ia menjatuhkan seluruh blok transaksi milik siapa pun
     * yang membungkus perintah ini.
     *
     * Dua syarat diulang pada penulisannya. `whereNotNull('deleted_at')` menutup pemulihan ganda
     * yang berlomba, dan `whereNull('purged_at')` menutup jendela sempit tetapi nyata: penghapusan
     * permanen yang berjalan terjadwal tepat di sela pemeriksaan di atas dan penulisan di sini.
     * Yang kalah menulis nol baris, dan nol baris di sini dilaporkan gagal — bukan diam-diam
     * dianggap berhasil.
     */
    private function lepaskanPenghapusan(Environment $lingkungan, string $status, ?CarbonInterface $berakhir): void
    {
        $isi = [
            'status' => $status,
            'deleted_at' => null,
            'purge_after' => null,
        ];

        if ($berakhir !== null) {
            $isi['expires_at'] = $berakhir;
        }

        $koneksi = DB::connection($lingkungan->getConnectionName());

        $terkena = (int) $koneksi->transaction(fn (): int => Environment::query()
            ->whereKey($lingkungan->id)
            ->whereNotNull('deleted_at')
            ->whereNull('purged_at')
            ->update($isi));

        if ($terkena !== 1) {
            throw new RuntimeException(
                'Barisnya berubah di sela pemeriksaan dan penulisan — ia sudah dipulihkan jalur '
                .'lain, atau isinya keburu dibuang permanen. Periksa keadaannya sebelum mencoba lagi.'
            );
        }

        $lingkungan->refresh();
    }

    /**
     * Masa berlaku baru dalam hari, dari opsi bila ada.
     *
     * Nol ditolak dengan alasan yang sama seperti masa tenggang nol pada sapuan: demo yang
     * dipulihkan lalu kedaluwarsa pada hari yang sama adalah pemulihan yang dibatalkan sapuan
     * berikutnya, dan yang terlihat operator hanyalah perintah yang seolah tidak mengerjakan apa-apa.
     */
    private function hari(): ?int
    {
        $nilai = $this->option('hari');

        if ($nilai === null || $nilai === '') {
            return self::PERPANJANGAN_HARI;
        }

        if (preg_match('/^[1-9][0-9]{0,3}$/', $nilai) !== 1) {
            $this->error(sprintf('Masa berlaku "%s" tidak dikenali. Isi jumlah hari, minimal 1.', $nilai));

            return null;
        }

        return (int) $nilai;
    }
}
