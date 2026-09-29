<?php

namespace App\Support\Modules\Contracts;

use App\Support\Database\AuditActor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom jejak pembuat dan pengubah terakhir di setiap tabel tenant, padanan `SystemCreatedBy` dan
 * `SystemModifiedBy` di Business Central (keputusan K-01, `docs/todo/AnalisaGapCoreErpkeBCPhase1`),
 * beserta trigger log perubahan (gap 6) dan versi baris (gap 2).
 *
 * Isinya ID `users.id`, diisi trigger PostgreSQL `coreerp_stamp_audit_actor` dari variabel sesi yang
 * dipasang {@see AuditActor}. Trigger, bukan event Eloquent, karena update lewat query builder tidak
 * melewati event model; jejak yang bolong diam-diam lebih buruk daripada tidak ada jejak.
 *
 * Kolom ini hanya ringkasan per baris: siapa yang membuat dan siapa yang terakhir mengubah. Riwayat
 * lengkap setiap perubahan milik log perubahan, satu tabel untuk semua record, yang mencatat hanya
 * tabel dan field yang dinyalakan (lihat {@see ChangeLogDefaults}).
 *
 * Versi baris padanan `SystemRowVersion` BC (K-03): angka yang dinaikkan trigger
 * `coreerp_bump_row_version` pada setiap UPDATE, apa pun jalurnya. Nilai yang ditulis kode untuk kolom
 * itu diabaikan. Penyimpanan dari form dan API memeriksanya lewat {@see RowVersion}.
 *
 * Tabel tenant baru, di Core maupun module, memanggil {@see self::add()} di `Schema::create` lalu
 * {@see self::attach()} sesudahnya. `AuditColumnsBoundaryTest` dan `ModuleTableBoundaryTest` menolak
 * tabel ber-`tenant_id` yang lupa.
 */
final class AuditColumns
{
    public const CREATED_BY = 'created_by_user_id';

    public const UPDATED_BY = 'updated_by_user_id';

    public const FUNCTION = 'coreerp_stamp_audit_actor';

    public const TRIGGER = 'stamp_audit_actor';

    public const VERSION = 'version';

    public const VERSION_FUNCTION = 'coreerp_bump_row_version';

    public const VERSION_TRIGGER = 'bump_row_version';

    /** Trigger `AFTER` log perubahan; keluar sebelum menyentuh barisnya bila log tidak menyala. */
    public const LOG_FUNCTION = 'coreerp_log_change';

    public const LOG_TRIGGER = 'log_change';

    /**
     * Tanpa foreign key ke `users`: pada database tenant sendiri tabel `users` tinggal di database
     * pusat, dan foreign key lintas batas itu dibatasi `FkMenyeberangBatasTest`.
     */
    public static function add(Blueprint $table): void
    {
        $table->unsignedBigInteger(self::CREATED_BY)->nullable();
        $table->unsignedBigInteger(self::UPDATED_BY)->nullable();

        // Dokumen aset sudah membawa `version` sebelum K-03, dan migration jejak area 1 memanggil helper
        // ini pada tabel yang sudah ada.
        $declared = array_filter($table->getColumns(), fn ($column): bool => $column->get('name') === self::VERSION);
        if ($declared === [] && ! Schema::hasColumn($table->getTable(), self::VERSION)) {
            $table->unsignedInteger(self::VERSION)->default(1);
        }
    }

    /**
     * Memasang ketiga trigger. Kolom primary key tabel diteruskan sebagai argumen trigger log, supaya record
     * berkunci gabungan tetap tercatat kuncinya, seperti field *Primary Key* di Change Log Entry BC.
     */
    public static function attach(string $table): void
    {
        $wrapped = DB::getQueryGrammar()->wrapTable($table);
        $keys = self::recordKey($table);
        $arguments = $keys === ['id'] ? '' : implode(', ', array_map(
            fn (string $key): string => DB::getPdo()->quote($key),
            $keys,
        ));

        DB::statement('CREATE OR REPLACE TRIGGER '.self::TRIGGER.' BEFORE INSERT OR UPDATE ON '.$wrapped
            .' FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION.'()');
        DB::statement('CREATE OR REPLACE TRIGGER '.self::VERSION_TRIGGER.' BEFORE UPDATE ON '.$wrapped
            .' FOR EACH ROW EXECUTE FUNCTION '.self::VERSION_FUNCTION.'()');
        DB::statement('CREATE OR REPLACE TRIGGER '.self::LOG_TRIGGER.' AFTER INSERT OR UPDATE OR DELETE ON '.$wrapped
            .' FOR EACH ROW EXECUTE FUNCTION '.self::LOG_FUNCTION.'('.$arguments.')');
    }

    public static function detach(string $table): void
    {
        $wrapped = DB::getQueryGrammar()->wrapTable($table);
        DB::statement('DROP TRIGGER IF EXISTS '.self::LOG_TRIGGER.' ON '.$wrapped);
        DB::statement('DROP TRIGGER IF EXISTS '.self::VERSION_TRIGGER.' ON '.$wrapped);
        DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON '.$wrapped);
    }

    /**
     * Kolom primary key, tanpa `tenant_id`: kunci record dibaca di dalam satu tenant.
     *
     * @return list<string>
     */
    private static function recordKey(string $table): array
    {
        $columns = array_column(DB::select(
            'select a.attname as column_name from pg_index i
               join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey)
              where i.indrelid = to_regclass(?) and i.indisprimary
              order by array_position(i.indkey::int2[], a.attnum)',
            [$table],
        ), 'column_name');

        $columns = array_values(array_diff($columns, ['tenant_id']));

        return $columns === [] ? ['id'] : $columns;
    }
}
