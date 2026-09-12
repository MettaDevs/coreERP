<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Memperbarui satu lingkungan di latar, supaya layarnya tidak menunggu seluruh armada.
 *
 * ## Kenapa lewat antrean, padahal penyiapan justru sinkron
 *
 * Karena jumlahnya berbeda, bukan karena pekerjaannya berbeda. Menyiapkan satu lingkungan adalah
 * satu pekerjaan yang operatornya memang sedang menatap; memperbarui armada adalah sepuluh
 * pekerjaan berurutan, dan satu permintaan HTTP yang menahan kesepuluhnya akan putus di tengah —
 * lalu operator melihat "gagal" untuk pekerjaan yang sebenarnya masih berjalan.
 *
 * Yang membuat pilihan ini tidak menghilangkan pemantauannya: `environment_operations` sudah diisi
 * tiap operasi sejak registry berdiri, jadi kemajuannya terbaca dari tabel — bukan dari balasan
 * HTTP yang sudah lama ditutup. Business Central menyatakan aturan yang sama sebagai aturan:
 * *"consumers should rely on the `status` field of the corresponding `EnvironmentOperation`
 * response to monitor the status of the underlying operation"*, dan bukan pada daftar
 * environmentnya.
 *
 * ## Tiga percobaan, bukan sepuluh
 *
 * Azure SQL Elastic Jobs mengulang sepuluh kali dengan backoff ×2,0, batas 120 detik, dan tenggat
 * langkah dua belas jam. Angka itu masuk akal untuk kegagalan jaringan sesaat pada armada ribuan
 * database.
 *
 * Migration yang ditolak PostgreSQL karena bentuknya salah akan ditolak lagi dengan cara yang sama
 * sepuluh kali, dan yang dihasilkan hanya sepuluh baris kegagalan yang identik. Tiga cukup untuk
 * melewati putusnya koneksi sesaat; sisanya memang butuh manusia.
 *
 * Tiap percobaan membuka barisnya sendiri di `environment_operations` — yang gagal ditutup sebagai
 * `failed` sebelum percobaan berikutnya boleh membuka kuncinya — jadi riwayat percobaannya terbaca
 * tanpa kolom penghitung.
 *
 * ## Yang dibawa hanya id, dan itu aturan
 *
 * Bukan model. Laravel men-deserialisasi payload job **sebelum** job middleware berjalan, jadi job
 * yang membawa model sisi tenant sebagai properti akan mencoba me-resolve-nya tanpa lingkungan
 * terikat — dan pada saat itu "database bawaan" bisa berarti database mana pun.
 */
final class UpgradeEnvironment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Migration Core beserta seluruh module bisa berjalan menit-menitan pada mesin yang sibuk. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $environmentId,
        public readonly ?int $requestedBy = null,
    ) {}

    /**
     * Jeda antar percobaan, dalam detik.
     *
     * Menaik, bukan tetap: kegagalan sesaat yang paling sering di sini adalah database yang sedang
     * dimuat ulang atau koneksi yang penuh, dan keduanya pulih dalam hitungan puluhan detik —
     * sementara mencoba lagi seketika hanya menambah beban pada hal yang sedang kewalahan.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('environment:upgrade', array_filter([
            'environment' => $this->environmentId,
            '--requested-by' => $this->requestedBy,
        ]));

        if ($exitCode === 0) {
            return;
        }

        /*
         * Melempar supaya antrean mencatatnya sebagai job gagal dan mencoba lagi.
         *
         * Alasan sebenarnya sudah tersimpan di `environment_operations` oleh perintahnya, beserta
         * langkah terakhir yang sempat tercapai. Yang dilempar di sini hanya penanda bagi antrean;
         * kalau ia memuat seluruh keluaran perintah, `failed_jobs` akan berisi salinan kedua dari
         * sesuatu yang sudah dicatat di tempat yang benar — dan dua salinan akan menyimpang.
         */
        throw new RuntimeException(sprintf(
            'Pembaruan lingkungan %s gagal. Alasannya tercatat di environment_operations.',
            $this->environmentId,
        ));
    }
}
