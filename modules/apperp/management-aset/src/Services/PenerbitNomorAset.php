<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\PenerbitNomor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Penerbitan nomor lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan klien HTTP yang dulu mengirim permintaan ke Core. Di dalam satu runtime,
 * permintaan itu tidak menambah apa pun selain kegagalan yang bisa terjadi: batas waktu,
 * percobaan ulang, dan 503 yang harus dijelaskan ke pengguna padahal Core berada di proses
 * yang sama.
 *
 * Yang lebih penting daripada kecepatan: penerbitan sekarang berjalan pada koneksi database
 * yang sama dengan dokumen yang sedang disimpan, jadi ia ikut di dalam transaksi dokumen itu.
 * Dokumen gagal, nomornya ikut batal — tidak ada lompatan nomor yang harus dijelaskan ke
 * pemeriksa. Itu alasan terkuat pemindahan ke satu runtime ini ada.
 *
 * Tanda tangan `issue()` sengaja dipertahankan persis seperti milik klien lama supaya
 * pemanggilnya tidak ikut berubah pada pull request yang hanya mengganti jalurnya.
 */
class PenerbitNomorAset
{
    public function __construct(private readonly PenerbitNomor $penerbit) {}

    /**
     * Menerbitkan satu nomor untuk reference milik module ini.
     *
     * @throws NumberSequenceException
     */
    public function issue(string $reference, string $tenantId, string $idempotencyKey, ?string $legalEntityId = null): string
    {
        try {
            $hasil = $this->penerbit->terbitkan(
                [
                    'tenant_id' => $tenantId,
                    'app_id' => 'management-aset',
                    'legal_entity_id' => $legalEntityId,
                ],
                $reference,
                $idempotencyKey,
            );
        } catch (Throwable $kegagalan) {
            throw $this->gagal('number_sequence_failed', $kegagalan->getMessage(), $reference, $tenantId, $kegagalan);
        }

        $nomor = $hasil['number'] ?? null;

        if (! is_string($nomor) || $nomor === '') {
            throw $this->gagal('number_sequence_invalid_response', 'Layanan nomor mengembalikan data yang tidak valid.', $reference, $tenantId);
        }

        return $nomor;
    }

    private function gagal(string $code, string $message, string $reference, string $tenantId, ?Throwable $sebab = null): NumberSequenceException
    {
        Log::warning('Penerbitan nomor gagal.', [
            'code' => $code,
            'reference' => $reference,
            'tenant_id' => $tenantId,
        ]);

        // Statusnya tidak lagi dioper dari sini. Core berada di proses yang sama, jadi kegagalan
        // penerbitan nomor tidak pernah lagi berarti "layanan belum dapat dihubungi" — ia selalu
        // berarti permintaannya sendiri tidak bisa dipenuhi, misalnya reference yang belum
        // terdaftar untuk tenant ini. Satu jawaban, jadi satu tempat: NumberSequenceException.
        return new NumberSequenceException($code, $message, $sebab);
    }
}
