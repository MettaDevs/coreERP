<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memasang trigger log perubahan (gap 6) di tabel module ini yang ber-`tenant_id`. Trigger kolom jejak
 * ikut dipasang ulang; keduanya idempoten. Log baru mencatat sesuatu setelah tenant menyalakannya di
 * setup log perubahan. Lihat {@see AuditColumns}.
 */
return new class extends Migration
{
    private const PREFIX = 'hr_';

    public function up(): void
    {
        foreach ($this->tables() as $table) {
            AuditColumns::attach($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            DB::statement('DROP TRIGGER IF EXISTS '.AuditColumns::LOG_TRIGGER.' ON '.DB::getQueryGrammar()->wrapTable($table));
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
