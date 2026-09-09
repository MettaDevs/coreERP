<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\KonteksPermintaan;
use App\Support\Modules\Contracts\MesinWorkflow;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pengajuan persetujuan lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan `WorkflowClient` yang mengirim permintaan ke `/api/internal/v1/workflow-instances`.
 *
 * **Klien lama tidak pernah bisa berhasil, dan tidak ada yang tahu.** Endpoint yang dipanggilnya
 * mewajibkan `initiator_membership_id`; klien itu tidak pernah mengirimnya. Setiap pengajuan
 * dekomisioning dijawab 422, ditangkap sebagai `RuntimeException`, lalu diubah menjadi 503
 * "Permintaan persetujuan belum dapat dikirim" — kalimat yang menyalahkan jaringan untuk
 * kesalahan yang seluruhnya ada di badan permintaan. Satu-satunya test yang menyentuh jalur ini
 * memalsukan jawaban Core dengan `Http::fake`, jadi ia hijau tanpa pernah menyentuh validasi
 * yang sesungguhnya.
 *
 * Itu bukan kebetulan melainkan sifat batas HTTP yang dipalsukan: jawaban palsu tidak
 * memeriksa apa pun, sehingga permintaan yang salah bentuk terlihat persis seperti yang benar.
 * Lewat kontrak, "lupa mengirim pengaju" bukan lagi kunci array yang hilang diam-diam — ia
 * parameter wajib, dan yang lupa tidak bisa memanggil sama sekali.
 *
 * Pengaju disebut dengan id pengguna dari konteks permintaan; Core yang menerjemahkannya
 * menjadi keanggotaan tenant. Module tidak pernah membaca tabel keanggotaan.
 */
class PersetujuanAset
{
    private const TIPE_DEKOMISIONING = 'management-aset.dekomisioning-aset-verification';

    public function __construct(
        private readonly MesinWorkflow $mesin,
        private readonly KonteksPermintaan $konteks,
    ) {}

    /**
     * Mengajukan satu dokumen dekomisioning ke alur persetujuan, dan mengembalikan id instancenya.
     *
     * Korelasinya id dokumen. Satu dokumen dekomisioning adalah satu rantai kerja, dan Core
     * mengembalikan korelasi itu pada event keputusan berhari-hari kemudian; tanpa ini Core
     * membangkitkan korelasinya sendiri dan module kehilangan jejaknya.
     *
     * @throws RuntimeException bila alur persetujuannya belum bisa dijalankan untuk tenant ini
     */
    public function ajukanDekomisioning(string $tenantId, string $legalEntityId, string $kunciIdempoten, string $documentId, string $assetId): string
    {
        try {
            $hasil = $this->mesin->ajukan(
                $tenantId,
                'management-aset',
                self::TIPE_DEKOMISIONING,
                $this->konteks->penggunaId(),
                $documentId,
                'dekomisioning:'.$kunciIdempoten,
                [
                    'legal_entity_id' => $legalEntityId,
                    'source_document_type' => 'dekomisioning-aset',
                    'source_document_id' => $documentId,
                    'decision_context' => ['document_id' => $documentId, 'asset_id' => $assetId],
                ],
            );
        } catch (ValidationException $kegagalan) {
            // Kunci pesannya milik permintaan **Core** — `pengaju`, `decision_context` — dan
            // bukan field yang pernah dikirim pengguna module. Dibiarkan lewat, ia muncul
            // sebagai kesalahan validasi pada field yang tidak ada di layar mana pun. Yang
            // diambil isinya saja; sama seperti yang dilakukan `DaftarSatuanAset`.
            throw new RuntimeException($this->pesanPertama($kegagalan), previous: $kegagalan);
        }

        return $hasil['id'];
    }

    private function pesanPertama(ValidationException $kegagalan): string
    {
        $pesan = $kegagalan->validator->errors()->first();

        return $pesan === '' ? 'Permintaan persetujuan tidak dapat diajukan.' : $pesan;
    }
}
