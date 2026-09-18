<?php

namespace Modules\Apperp\ManagementAset\Listeners;

use App\Support\Modules\Contracts\KeputusanWorkflowDiambil;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Models\support\ProcessedCoreEvent;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Menerapkan keputusan dekomisioning ke dokumen dan asetnya.
 *
 * Menggantikan `WorkflowDecisionController`, yang menerima keputusan yang sama sebagai
 * permintaan HTTP bertanda tangan HMAC. Isi transaksinya dipertahankan hampir persis; yang
 * hilang hanyalah lapisan yang memang milik HTTP — verifikasi tanda tangan, validasi amplop,
 * dan jawaban 404 untuk dokumen yang tidak cocok.
 *
 * **Tiga hal yang harus berbeda dari sebuah controller, dan alasannya.**
 *
 * 1. **Menyaring, bukan memvalidasi.** Event ini dipancarkan untuk setiap keputusan Core,
 *    termasuk milik module lain. Amplop yang bukan urusan module ini dilewati diam-diam;
 *    menolaknya sebagai kesalahan berarti keputusan module lain gagal karena module ini ada.
 * 2. **Mencatat, bukan `abort`.** Dokumen yang tidak ditemukan dulu menjadi 404 kepada Core.
 *    Di dalam proses, melempar dari sini membatalkan transaksi keputusannya — persetujuan
 *    yang sah gagal karena module tidak menemukan dokumennya. Yang benar mencatatnya sebagai
 *    peringatan dan membiarkan keputusan Core berdiri.
 * 3. **Dedup tetap ada.** Barisnya sekarang datang dari satu jalur, tetapi `aset_processed_core_events`
 *    dipertahankan: id event yang dipancarkan sama dengan id baris outbox, jadi kalau kelak
 *    baris yang sama juga terkirim lewat HTTP — module dipasang kembali di luar proses, atau
 *    outbox diputar ulang — pemrosesan keduanya tetap terhitung sekali.
 */
class TerapkanKeputusanDekomisioning
{
    private const TIPE = 'management-aset.dekomisioning-aset-verification';

    public function handle(KeputusanWorkflowDiambil $event): void
    {
        $data = $event->data;

        if (($data['workflow_type'] ?? null) !== self::TIPE || ($data['source_document_type'] ?? null) !== 'dekomisioning-aset') {
            return;
        }

        $keputusan = $data['decision'] ?? null;
        $instanceId = $data['workflow_instance_id'] ?? null;
        $documentId = $data['source_document_id'] ?? null;
        $asetId = $data['decision_context']['aset_id'] ?? null;

        if (! in_array($keputusan, ['approved', 'rejected'], true) || ! is_string($instanceId) || ! is_string($documentId) || ! is_string($asetId)) {
            Log::warning('Keputusan workflow dekomisioning datang tanpa data yang lengkap.', [
                'event_id' => $event->idEvent,
                'tenant_id' => $event->tenantId,
            ]);

            return;
        }

        // Dokumennya dicari dan dikunci **sebelum** baris dedup ditulis, dan urutan itu bukan
        // selera. Kalau dedup lebih dulu, sebuah amplop yang tidak cocok dengan dokumen mana
        // pun tetap menghabiskan jatah "sudah diproses" — dan pengiriman ulang yang sah
        // berikutnya akan dilewati diam-diam. Controller lama tidak punya masalah ini karena
        // `abort` di dalamnya membatalkan transaksi beserta baris dedupnya; listener yang
        // melempar akan membatalkan keputusan Core, jadi jalan itu tertutup di sini.
        $dokumen = DokumenSiklusAset::query()->where([
            'id' => $documentId,
            'jenis_dokumen' => 'dekomisioning-aset',
        ])->lockForUpdate()->first();

        // Instance yang tercatat pada dokumen boleh **belum ada**, dan itu bukan kelonggaran
        // melainkan keharusan. Alur persetujuan yang grafnya tidak punya langkah persetujuan —
        // misalnya sebuah kondisi yang langsung menuju Selesai — sudah berstatus `approved`
        // pada saat pengajuan, jadi keputusannya sampai ke sini **sebelum** pemanggil sempat
        // menuliskan id instancenya ke dokumen. Menuntut id itu sudah tercatat membuat dokumen
        // semacam ini menggantung selamanya pada `submitted` sementara instancenya `approved`.
        //
        // Waktu jalurnya HTTP, keadaan ini tidak pernah muncul: keputusan baru dikirim perintah
        // terjadwal, berjam-jam setelah idnya tersimpan. Ia hanya terlihat setelah pengiriman
        // menjadi seketika — contoh lain dari perbedaan yang tidak muncul sebagai kesalahan.
        $instanceCocok = $dokumen !== null
            && ($dokumen->workflow_instance_id === $instanceId || $dokumen->workflow_instance_id === null);

        if ($dokumen === null || ! $instanceCocok || $dokumen->aset_id !== $asetId) {
            Log::warning('Keputusan workflow dekomisioning tidak cocok dengan dokumen mana pun.', [
                'event_id' => $event->idEvent,
                'tenant_id' => $event->tenantId,
                'workflow_instance_id' => $instanceId,
                'source_document_id' => $documentId,
            ]);

            return;
        }

        // `tenant_id` ditulis eksplisit karena `insertOrIgnore` tidak membuat instance model,
        // sehingga pengisian otomatis oleh trait tenant tidak berjalan. Alasan memakai
        // `insertOrIgnore` alih-alih menyimpan lalu menangkap pelanggaran unique ada di
        // docblock `ProcessedCoreEvent`: pada PostgreSQL statement yang gagal membatalkan
        // seluruh transaksi, dan transaksi ini adalah transaksi keputusan Core.
        $sudahPernah = ProcessedCoreEvent::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'tenant_id' => $event->tenantId,
            'event_id' => $event->idEvent,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 0;

        if ($sudahPernah) {
            return;
        }

        DokumenSiklusAset::query()->whereKey($dokumen->id)
            ->update(['status' => $keputusan, 'updated_at' => now()]);

        if ($keputusan !== 'approved') {
            return;
        }

        // Aset yang sudah dilepas tidak ditarik kembali menjadi terdekomisioning: pelepasan
        // adalah akhir masa hidupnya, dan persetujuan yang datang belakangan tidak membatalkannya.
        Aset::query()
            ->whereKey($dokumen->aset_id)
            ->whereNotIn('lifecycle_state', [StatusAset::DILEPAS])
            ->update(['lifecycle_state' => StatusAset::DIHENTIKAN, 'updated_at' => now()]);
    }
}
