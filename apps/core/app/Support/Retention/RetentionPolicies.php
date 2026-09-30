<?php

declare(strict_types=1);

namespace App\Support\Retention;

use App\Support\ChangeLog\AlwaysLoggedTables;
use InvalidArgumentException;

/**
 * Daftar tabel yang boleh diretensi (K-05, K-14). Hanya tabel log dan berkas teknis yang boleh masuk; tabel
 * data bisnis tidak pernah didaftarkan, karena penghapusannya adalah pengarsipan (`deleted_at`).
 */
final class RetentionPolicies
{
    /** @return list<RetentionPolicy> */
    public static function all(): array
    {
        return [
            new RetentionPolicy(
                'number_sequence_audit', 'Catatan audit penomoran', 'number_sequence_audit_events', 'occurred_at',
                365, 'coreerp.audit_retention_days', tenantVia: 'sequence_id',
            ),
            new RetentionPolicy(
                'number_sequence_confirmed_pool', 'Cadangan nomor berurutan yang sudah dipakai (nomor yang sudah terbit tidak pernah diulang)', 'number_sequence_continuous_pool', 'updated_at',
                7, 'coreerp.confirmed_pool_retention_days', tenantVia: 'sequence_id',
                filters: [['column' => 'status', 'values' => ['confirmed']]],
            ),
            new RetentionPolicy(
                'report_exports', 'Hasil ekspor laporan', 'report_exports', 'created_at',
                1, 'reporting.retention_days', fileColumn: 'file_path',
            ),
            new RetentionPolicy(
                'change_log_access', 'Riwayat perubahan hak akses dan setelan pencatatan', 'change_log_entries', 'changed_at',
                365, null, filters: [['column' => 'table_name', 'values' => AlwaysLoggedTables::NAMES]],
            ),
            new RetentionPolicy(
                'change_log_other', 'Riwayat perubahan data lainnya', 'change_log_entries', 'changed_at',
                28, null, filters: [['column' => 'table_name', 'values' => AlwaysLoggedTables::NAMES, 'exclude' => true]],
            ),
            new RetentionPolicy(
                'retention_policy_log', 'Catatan penerapan retensi', 'retention_policy_log_entries', 'created_at',
                28, 'coreerp.retention_log_retention_days',
            ),
        ];
    }

    public static function find(string $code): RetentionPolicy
    {
        foreach (self::all() as $policy) {
            if ($policy->code === $code) {
                return $policy;
            }
        }

        throw new InvalidArgumentException("Kebijakan retensi [{$code}] tidak terdaftar.");
    }
}
