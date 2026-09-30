<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu akun pengguna (keanggotaan tenant) paling banyak tertaut ke satu pekerja, dihitung dari pekerja yang
 * belum diarsipkan (TODO analisa gap BC 9.1, B-9).
 *
 * Indeks unik lama `(tenant_id, core_membership_id)` lahir sebelum soft delete. Dengan indeks itu pekerja yang
 * sudah diarsipkan tetap memegang akunnya, dan akun itu tidak bisa ditautkan ke pekerja penggantinya. Indeks
 * parsial ini mengikuti aturan repo: indeks unik wajib `WHERE deleted_at IS NULL`. Baris tanpa tautan tidak
 * ikut dihitung.
 *
 * Melonggarkan, bukan mengetatkan: setiap baris yang lolos indeks lama juga lolos indeks ini, dan kode rilis
 * sebelumnya tetap berjalan di atasnya.
 */
return new class extends Migration
{
    private const INDEX = 'hr_workers_core_membership_active_unique';

    public function up(): void
    {
        if (Schema::hasIndex('hr_workers', 'hr_workers_tenant_id_core_membership_id_unique')) {
            DB::statement('ALTER TABLE hr_workers DROP CONSTRAINT hr_workers_tenant_id_core_membership_id_unique');
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX.' ON hr_workers (tenant_id, core_membership_id) '.
            'WHERE deleted_at IS NULL AND core_membership_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        DB::statement('ALTER TABLE hr_workers ADD CONSTRAINT hr_workers_tenant_id_core_membership_id_unique UNIQUE (tenant_id, core_membership_id)');
    }
};
