<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Environment;
use App\Models\EnvironmentOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Membuka, memegang, dan menutup satu baris operasi atas sebuah lingkungan.
 *
 * Diangkat dari `SiapkanLingkungan` dan `KonversiLingkungan` pada 12 September 2026, ketika
 * keduanya sudah memegang salinan yang sama. Bukan demi kerapian: yang disalin di sini adalah
 * **protokol kunci**, dan dua salinan protokol kunci adalah dua kesempatan untuk menyimpang pada
 * hal yang justru tidak terlihat ketika ia salah. Operasi ketiga yang lahir besok mewarisi
 * perilaku yang sama tanpa menyalin apa pun.
 *
 * ## Kuncinya, dan kenapa ia butuh masa berlaku
 *
 * Partial unique index `environment_operations_satu_berjalan` mengizinkan tepat satu operasi
 * berjalan per lingkungan. Itu kunci termurah yang tersedia tanpa Redis — dan tanpa pasangannya ia
 * berubah menjadi kebuntuan.
 *
 * Sebabnya: satu-satunya pemulihan yang desain ini izinkan adalah **menjalankan ulang
 * perintahnya**. Proses yang mati keras — OOM, container dibunuh, koneksi putus di tengah
 * migration — tidak sempat menutup barisnya, dan indeks yang sama lalu menolak percobaan ulang
 * yang merupakan jalan keluarnya. Penjaganya menghalangi persis pada keadaan yang paling
 * membutuhkannya, dan operator hanya punya UPDATE tangan pada pukul dua pagi.
 *
 * Karena itu tiap operasi membawa tenggat, dan percobaan berikutnya menyatakan gagal yang sudah
 * lewat tenggatnya lalu mengambil alih — perbaikan maju, bukan kompensasi.
 *
 * ## Yang direbut hanya yang tenggatnya lewat
 *
 * Itu yang membedakan pengambilalihan dari sekadar menabrak kunci orang. Penjaga yang merebut apa
 * saja akan lulus test "operasi mati dapat diambil alih" juga — karena itu pasangan hijaunya
 * wajib: operasi yang masih hidup terbukti **tidak** direbut.
 */
trait MemegangOperasiLingkungan
{
    /**
     * Berapa lama sebuah operasi boleh memegang kuncinya sebelum boleh direbut.
     *
     * Dinyatakan method, bukan konstanta, karena tiap operasi punya ukuran wajarnya sendiri:
     * penyiapan database berhitung menit, penyalinan bisa jauh lebih lama. Yang dijaga bukan
     * operasi yang lambat melainkan operasi yang prosesnya sudah tidak ada — dan merebut milik
     * proses yang sebenarnya masih hidup jauh lebih mahal daripada menunggu.
     *
     * Ini bukan heartbeat. Operasi yang berjalan lebih lama dari tenggatnya akan direbut meski
     * sehat, dan itu jawaban yang benar hanya selama tidak ada operasi yang memang wajar berjalan
     * selama itu. Operasi panjang sungguhan harus memperpanjang tenggatnya sendiri selagi berjalan.
     */
    abstract protected function tenggatOperasiMenit(): int;

    /**
     * Membuka satu baris operasi, atau menolak karena sudah ada yang berjalan.
     *
     * Tidak ada pemeriksaan "apakah ada yang berjalan" di depannya, dan itu disengaja: pemeriksaan
     * semacam itu hanya memindahkan balapan satu baris ke atas tanpa menutupnya. Barisnya
     * disisipkan apa adanya, dan bentrokan yang muncul diterjemahkan.
     */
    protected function bukaOperasi(Environment $lingkungan, string $jenis, bool $ambilAlih = true): ?EnvironmentOperation
    {
        $koneksi = DB::connection((new EnvironmentOperation)->getConnectionName());

        try {
            // Savepoint, bukan hiasan. PostgreSQL membatalkan **seluruh** blok transaksi begitu satu
            // perintah di dalamnya ditolak, jadi sisipan yang sejak awal memang boleh ditolak akan
            // menjatuhkan transaksi milik siapa pun yang kebetulan membungkus perintah ini.
            return $koneksi->transaction(fn (): EnvironmentOperation => EnvironmentOperation::create([
                'environment_id' => $lingkungan->id,
                'operation' => $jenis,
                'status' => 'running',
                'step' => 'mulai',
                'started_at' => now(),
                'lease_until' => now()->addMinutes($this->tenggatOperasiMenit()),
            ]));
        } catch (QueryException $bentrok) {
            if (! str_contains($bentrok->getMessage(), 'environment_operations_satu_berjalan')) {
                throw $bentrok;
            }

            if ($ambilAlih && $this->ambilAlihYangKedaluwarsa($lingkungan)) {
                return $this->bukaOperasi($lingkungan, $jenis, ambilAlih: false);
            }

            $this->error(sprintf(
                'Sudah ada operasi yang berjalan atas environment "%s" dan tenggatnya belum lewat. '
                .'Tunggu sampai ia selesai, atau tunggu tenggatnya habis — percobaan berikutnya akan '
                .'mengambil alih sendiri.',
                $lingkungan->slug,
            ));

            return null;
        }
    }

    /**
     * Menyatakan gagal operasi yang tenggatnya sudah lewat, supaya percobaan ini boleh masuk.
     *
     * Alasannya ditulis apa adanya beserta langkah terakhir yang sempat tercapai, dan barisnya
     * tidak dihapus — riwayat yang kehilangan operasi mati menghilangkan satu-satunya petunjuk
     * kenapa sebuah lingkungan tertinggal.
     */
    protected function ambilAlihYangKedaluwarsa(Environment $lingkungan): bool
    {
        $kedaluwarsa = EnvironmentOperation::query()
            ->where('environment_id', $lingkungan->id)
            ->where('status', 'running')
            ->where('lease_until', '<', now())
            ->first();

        if (! $kedaluwarsa instanceof EnvironmentOperation) {
            return false;
        }

        $kedaluwarsa->update([
            'status' => 'failed',
            'finished_at' => now(),
            'lease_until' => null,
            'failure_message' => sprintf(
                'Tenggatnya habis pada %s tanpa pernah ditutup — prosesnya berhenti tanpa sempat '
                .'melaporkan apa pun. Operasi ini diambil alih percobaan berikutnya, dan langkah '
                .'terakhir yang sempat tercapai adalah "%s".',
                (string) $kedaluwarsa->getOriginal('lease_until'),
                $kedaluwarsa->step ?? 'tidak tercatat',
            ),
        ]);

        $this->warn(sprintf(
            'Operasi %s yang tenggatnya sudah lewat ditandai gagal dan diambil alih.',
            $kedaluwarsa->id,
        ));

        return true;
    }

    /**
     * Menutup operasi sebagai gagal, beserta langkah terakhir yang tercapai.
     *
     * `environment_operations_gagal_beralasan` menolak baris gagal tanpa alasan, jadi pesan kosong
     * pun diganti nama kelas pengecualiannya: sebuah baris yang ditolak database di sini akan
     * menelan sebab kegagalan yang sebenarnya dan menggantinya dengan sebab palsu.
     *
     * Tenggatnya dilepas bersamaan. Ia hanya berarti selama operasinya berjalan, dan baris selesai
     * yang masih membawa tenggat terbaca seolah ia masih memegang sesuatu.
     */
    protected function tutupOperasiSebagaiGagal(EnvironmentOperation $operasi, string $langkah, Throwable $sebab): void
    {
        $pesan = trim($sebab->getMessage());

        if ($pesan === '') {
            $pesan = $sebab::class;
        }

        $operasi->update([
            'status' => 'failed',
            'step' => $langkah,
            'failure_message' => mb_substr($pesan, 0, 2000),
            'finished_at' => now(),
            'lease_until' => null,
        ]);
    }
}
