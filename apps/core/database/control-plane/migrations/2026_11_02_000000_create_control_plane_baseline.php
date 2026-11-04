<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $schema = $connection->getConfig('search_path') ?? 'public';
        if (is_array($schema) && count($schema) === 1) {
            $schema = reset($schema);
        }

        if (! is_string($schema) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/D', $schema) !== 1) {
            throw new LogicException('Baseline control memerlukan tepat satu nama schema PostgreSQL yang sah.');
        }

        $existing = $connection->selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = ? AND (
                    (c.relkind IN ('r', 'p', 'v', 'm', 'f', 'c') AND c.relname <> 'control_plane_migrations')
                    OR (c.relkind = 'S' AND NOT EXISTS (
                        SELECT 1 FROM pg_depend d JOIN pg_class owner ON owner.oid = d.refobjid
                        WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype IN ('a', 'i')
                            AND owner.relnamespace = c.relnamespace AND owner.relname = 'control_plane_migrations'
                    ))
                )
                UNION ALL
                SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = ?
                UNION ALL
                SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                WHERE n.nspname = ? AND t.typrelid = 0 AND t.typelem = 0
            ) AS populated
            SQL, [$schema, $schema, $schema]);

        if ($existing->populated) {
            throw new LogicException('Baseline control hanya dapat dijalankan pada schema kosong selain riwayat migration control.');
        }

        $sql = file_get_contents(__DIR__.'/../baseline.sql');
        if ($sql === false) {
            throw new RuntimeException('Berkas baseline control tidak dapat dibaca.');
        }

        $quotedSchema = '"'.$schema.'"';
        $connection->statement('CREATE SCHEMA IF NOT EXISTS '.$quotedSchema);
        $connection->unprepared(str_replace('__CONTROL_SCHEMA__', $quotedSchema, $sql)); // @phpstan-ignore argument.type (SQL berasal dari baseline yang direview; hanya identifier schema tervalidasi yang diganti.)
    }
};
