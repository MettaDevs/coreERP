<?php

use App\Support\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi baris di setiap tabel module ini yang ber-`tenant_id` (K-03), dinaikkan trigger Core
 * `coreerp_bump_row_version` pada setiap UPDATE. Lihat {@see AuditColumns}.
 *
 * Tabel yang sudah membawa `version` sendiri tetap memakai kolomnya; trigger hanya mengambil alih
 * penaikannya. Tabelnya dibaca dari skema, dibatasi awalan module ini, supaya tidak ada yang terlewat.
 *
 * `down()` hanya melepas trigger: sebagian kolom `version` sudah ada sebelum migration ini.
 */
return new class extends Migration
{
    private const PREFIX = 'hr_';

    public function up(): void
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasColumn($table, AuditColumns::VERSION)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedInteger(AuditColumns::VERSION)->default(1));
            }
            AuditColumns::attach($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            DB::statement('DROP TRIGGER IF EXISTS '.AuditColumns::VERSION_TRIGGER.' ON '.DB::getQueryGrammar()->wrapTable($table));
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
