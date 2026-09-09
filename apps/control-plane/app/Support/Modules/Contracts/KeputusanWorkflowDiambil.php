<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Keputusan sebuah alur persetujuan sudah final.
 *
 * Ini jalur masuk Core → module setelah keduanya berada di satu proses. Sebelumnya
 * keputusan disampaikan lewat perintah terjadwal yang mengirim HTTP bertanda tangan HMAC ke
 * setiap app penerima; module memverifikasi tanda tangan, memvalidasi amplopnya, lalu
 * memperbarui dokumennya. Semua itu tetap diperlukan untuk penerima yang **memang** berada
 * di luar proses, dan karena itu `PublishWorkflowEvents` tidak dihapus.
 *
 * Yang tidak lagi masuk akal adalah menempuh jalur itu untuk module yang kodenya berjalan di
 * runtime yang sama. Tiga akibatnya nyata, bukan soal kerapian:
 *
 * 1. **Jeda.** Keputusan baru sampai pada jadwal berikutnya. Pengguna yang menyetujui
 *    dekomisioning melihat asetnya masih aktif sampai perintah terjadwal berjalan.
 * 2. **Kegagalan diam.** Endpoint yang tidak terjangkau membuat barisnya tertinggal di
 *    outbox tanpa ada yang melihat, dan dokumennya tertinggal pada status lama.
 * 3. **Dua kebenaran.** Instance sudah `approved` di Core sementara dokumennya belum, dan
 *    selisih itu bisa bertahan tanpa batas waktu.
 *
 * Sebagai event in-process, listener berjalan **di dalam transaksi yang sama** dengan
 * keputusannya. Dokumen dan instance berpindah status bersama-sama, atau tidak sama sekali.
 *
 * **Kenapa ia tinggal di `Contracts` dan bukan di `App\Events`.** Yang boleh disebut sebuah
 * module hanyalah `App\Support\Modules\Contracts`, dan penjaga batas menolak sisanya. Aturan
 * itu bukan halangan yang perlu disiasati di sini: sebuah event yang didengarkan module
 * memang bagian dari permukaan yang dijanjikan Core, sama seperti antarmuka di sebelahnya.
 * Menaruhnya di tempat lain berarti menjanjikan sesuatu yang tidak pernah diakui sebagai
 * janji, dan bentuknya bebas berubah tanpa ada yang menahan.
 *
 * `$idEvent` sengaja sama dengan id baris outbox, bukan id baru. Itu yang membuat dedup
 * milik module tetap berlaku untuk kedua jalur: kalau kelak baris yang sama juga terkirim
 * lewat HTTP, module mengenalinya sebagai event yang sudah pernah diproses.
 */
final class KeputusanWorkflowDiambil
{
    /** @param array<string, mixed> $data Isi `data` pada amplop `core.workflow.decision.v2`. */
    public function __construct(
        public readonly string $idEvent,
        public readonly string $tenantId,
        public readonly string $idKorelasi,
        public readonly ?string $legalEntityId,
        public readonly array $data,
    ) {}
}
