<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\ControlPlane\OwnedByControlPlane;
use App\Support\Modules\Contracts\AuditColumns;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Membaca skema database untuk mencari tabel ber-`tenant_id` yang tidak membawa kolom jejak (K-01),
 * versi baris (K-03), atau trigger pengisinya. Dipakai penjaga Core dan penjaga migration module.
 */
final class AuditColumnInspector
{
    public function __construct(private readonly Connection $connection) {}

    /** @return list<string> */
    public function tenantTables(): array
    {
        return array_column($this->connection->select(
            "select c.table_name from information_schema.columns c
               join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
              where c.table_schema = current_schema() and t.table_type = 'BASE TABLE' and c.column_name = 'tenant_id'
              order by c.table_name",
        ), 'table_name');
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $withoutRowVersion  tabel yang kolom `version`-nya bermakna lain
     * @return array<string, string> tabel => apa yang kurang
     */
    public function missing(array $tables, array $withoutRowVersion = []): array
    {
        $missing = [];

        foreach ($tables as $table) {
            $columns = array_column($this->connection->select(
                'select column_name, data_type from information_schema.columns where table_schema = current_schema() and table_name = ?',
                [$table],
            ), 'data_type', 'column_name');

            $problems = [];
            foreach ([AuditColumns::CREATED_BY, AuditColumns::UPDATED_BY] as $column) {
                if (! isset($columns[$column])) {
                    $problems[] = "kolom {$column} tidak ada";
                } elseif ($columns[$column] !== 'bigint') {
                    $problems[] = "kolom {$column} bertipe {$columns[$column]}, bukan bigint";
                }
            }

            $triggers = [AuditColumns::TRIGGER, AuditColumns::LOG_TRIGGER];
            if (! in_array($table, $withoutRowVersion, true)) {
                $triggers[] = AuditColumns::VERSION_TRIGGER;
                $version = $columns[AuditColumns::VERSION] ?? null;
                if ($version === null) {
                    $problems[] = 'kolom '.AuditColumns::VERSION.' tidak ada';
                } elseif ($version !== 'integer') {
                    $problems[] = 'kolom '.AuditColumns::VERSION." bertipe {$version}, bukan integer";
                }
            }

            foreach ($triggers as $trigger) {
                $hasTrigger = $this->connection->selectOne(
                    'select 1 as ada from pg_trigger where tgname = ? and tgrelid = to_regclass(?) and not tgisinternal',
                    [$trigger, $table],
                ) !== null;
                if (! $hasTrigger) {
                    $problems[] = "trigger {$trigger} tidak terpasang";
                }
            }

            if ($problems !== []) {
                $missing[$table] = implode(', ', $problems);
            }
        }

        return $missing;
    }

    /**
     * Tabel sisi pusat, diturunkan dari model ber-`OwnedByControlPlane`. Tabel ini tidak ikut penjaga
     * tabel tenant walau membawa `tenant_id`.
     *
     * @return list<string>
     */
    public static function controlPlaneTables(): array
    {
        $tables = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Models'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\Models\\'.str_replace(
                [app_path('Models').DIRECTORY_SEPARATOR, '/', '.php'],
                ['', '\\', ''],
                $file->getPathname(),
            );

            if (class_exists($class) && is_subclass_of($class, Model::class)
                && in_array(OwnedByControlPlane::class, class_uses_recursive($class), true)) {
                $tables[] = (new $class)->getTable();
            }
        }

        return $tables;
    }
}
