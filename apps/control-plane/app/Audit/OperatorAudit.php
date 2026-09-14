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
}
