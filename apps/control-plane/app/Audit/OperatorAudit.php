<?php

declare(strict_types=1);

namespace ControlPlane\Audit;

use ControlPlane\Models\OperatorAuditEvent;
use Illuminate\Http\Request;

/**
 * Mencatat tindakan operator yang mengubah sesuatu di luar layarnya sendiri.
 *
 * Dipanggil **di dalam transaksi yang sama** dengan perubahannya. Jejak yang ditulis sesudah
 * perubahan berhasil dapat hilang bila penulisannya gagal, dan perubahan tanpa jejak adalah persis
 * keadaan yang hendak dicegah; jejak yang ditulis sebelum perubahan dapat mencatat tindakan yang
 * tidak pernah terjadi. Transaksi yang sama menghapus kedua kemungkinan itu.
 *
 * Detailnya tidak pernah memuat rahasia: token pendaftaran, kata sandi, dan isi lisensi dicatat
 * sebagai keberadaannya, bukan nilainya.
 */
final class OperatorAudit
{
    /** @param  array<string, mixed>  $detail */
    public static function record(Request $request, string $action, string $subjectType, ?string $subjectId, array $detail = []): OperatorAuditEvent
    {
        $user = $request->user();

        return OperatorAuditEvent::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'detail' => $detail,
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * Mencatat tindakan yang dijalankan konsol sendiri, bukan operator — misalnya perpanjangan lisensi
     * lewat jawaban laporan agen.
     *
     * Pengguna selalu kosong, dan itu ditulis tegas di sini alih-alih dibaca dari permintaan. Kolom
     * yang kosong adalah satu-satunya tanda di jejak ini bahwa tidak ada manusia yang memutuskannya;
     * penjaga autentikasi yang kelak kebetulan mengenali seseorang di permintaan agen tidak boleh
     * membuat perpanjangan otomatis tercatat atas nama orang itu.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function recordBySystem(?string $ipAddress, string $action, string $subjectType, ?string $subjectId, array $detail = []): OperatorAuditEvent
    {
        return OperatorAuditEvent::query()->create([
            'user_id' => null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'detail' => $detail,
            'ip_address' => $ipAddress,
            'occurred_at' => now(),
        ]);
    }
}
