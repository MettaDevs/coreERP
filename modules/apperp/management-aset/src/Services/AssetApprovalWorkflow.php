<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\WorkflowEngine;
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
class AssetApprovalWorkflow
{
    private const DECOMMISSIONING_TYPE = 'management-aset.dekomisioning-aset-verification';

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RequestContext $context,
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
    public function submitDecommissioning(string $tenantId, string $legalEntityId, string $idempotencyKey, string $documentId, string $assetId): string
    {
        try {
            $result = $this->engine->submit(
                $tenantId,
                'management-aset',
                self::DECOMMISSIONING_TYPE,
                $this->context->userId(),
                $documentId,
                'dekomisioning:'.$idempotencyKey,
                [
                    'legal_entity_id' => $legalEntityId,
                    'source_document_type' => 'dekomisioning-aset',
                    'source_document_id' => $documentId,
                    'decision_context' => ['document_id' => $documentId, 'aset_id' => $assetId],
                ],
            );
        } catch (ValidationException $failure) {
            // Kunci pesannya milik permintaan **Core** — `pengaju`, `decision_context` — dan
            // bukan field yang pernah dikirim pengguna module. Dibiarkan lewat, ia muncul
            // sebagai kesalahan validasi pada field yang tidak ada di layar mana pun. Yang
            // diambil isinya saja; sama seperti yang dilakukan `AssetUnitOfMeasureDirectory`.
            throw new RuntimeException($this->firstMessage($failure), previous: $failure);
        }

        return $result['id'];
    }

    private function firstMessage(ValidationException $failure): string
    {
        $message = $failure->validator->errors()->first();

        return $message === '' ? 'Permintaan persetujuan tidak dapat diajukan.' : $message;
    }
}
