<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom jejak pembuat dan pengubah terakhir di setiap tabel module ini yang ber-`tenant_id` (K-01),
 * diisi trigger Core `coreerp_stamp_audit_actor`. Lihat {@see AuditColumns}.
 *
 * Tabelnya dibaca dari skema, dibatasi awalan module ini, supaya tidak ada tabel yang terlewat.
 */
return new class extends Migration
{
    private const PREFIX = 'hr_';

    public function up(): void
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasColumn($table, AuditColumns::CREATED_BY)) {
                Schema::table($table, fn (Blueprint $blueprint) => AuditColumns::add($blueprint));
            }
            AuditColumns::attach($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            AuditColumns::detach($table);
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn([AuditColumns::CREATED_BY, AuditColumns::UPDATED_BY]));
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_column(DB::select(
            "select c.table_name from information_schema.columns c
               join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
              where c.table_schema = current_schema() and t.table_type = 'BASE TABLE'
                and c.column_name = 'tenant_id' and left(c.table_name, ?) = ?
              order by c.table_name",
            [strlen(self::PREFIX), self::PREFIX],
        ), 'table_name');
    }
};
