<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\Contracts\AuditColumns;
use Illuminate\Database\Connection;

/**
 * Membaca skema database untuk mencari tabel ber-`tenant_id` yang tidak membawa kolom jejak (K-01)
 * atau trigger pengisinya. Dipakai penjaga Core dan penjaga migration module.
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
     * @return array<string, string> tabel => apa yang kurang
     */
    public function missing(array $tables): array
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

            $hasTrigger = $this->connection->selectOne(
                'select 1 as ada from pg_trigger where tgname = ? and tgrelid = to_regclass(?) and not tgisinternal',
                [AuditColumns::TRIGGER, $table],
            ) !== null;
            if (! $hasTrigger) {
                $problems[] = 'trigger '.AuditColumns::TRIGGER.' tidak terpasang';
            }

            if ($problems !== []) {
                $missing[$table] = implode(', ', $problems);
            }
        }

        return $missing;
    }
}
