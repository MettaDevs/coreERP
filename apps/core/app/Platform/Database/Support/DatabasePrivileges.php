<?php

declare(strict_types=1);

namespace App\Platform\Database\Support;

use App\Platform\Environment\Support\EnvironmentConnection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabasePrivileges
{
    public function __construct(private readonly EnvironmentConnection $connections) {}

    public function defaults(string $migration, string $runtime): void
    {
        if ($migration === $runtime) {
            return;
        }
        $role = $this->runtimeRole($runtime);
        $database = DB::connection($migration);
        foreach ([
            'ALTER DEFAULT PRIVILEGES REVOKE ALL ON TABLES FROM PUBLIC',
            'ALTER DEFAULT PRIVILEGES REVOKE ALL ON SEQUENCES FROM PUBLIC',
            'ALTER DEFAULT PRIVILEGES REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC',
            'ALTER DEFAULT PRIVILEGES REVOKE ALL ON SCHEMAS FROM PUBLIC',
            'ALTER DEFAULT PRIVILEGES GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.$role,
            'ALTER DEFAULT PRIVILEGES GRANT USAGE, SELECT ON SEQUENCES TO '.$role,
            'ALTER DEFAULT PRIVILEGES GRANT EXECUTE ON FUNCTIONS TO '.$role,
            'ALTER DEFAULT PRIVILEGES GRANT USAGE ON SCHEMAS TO '.$role,
        ] as $sql) {
            $database->getPdo()->exec($sql);
        }
    }

    public function prepare(string $migration, string $runtime): void
    {
        if ($migration === $runtime) {
            return;
        }
        $role = $this->runtimeRole($runtime);
        $database = DB::connection($migration);
        $configuration = $this->connections->configuration($migration);
        if (($configuration['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Pemisahan privilege database memerlukan PostgreSQL.');
        }
        $this->defaults($migration, $runtime);
        $search = $configuration['search_path'] ?? 'public';
        $schemas = is_array($search) ? $search : explode(',', (string) $search);
        foreach ($schemas as $schema) {
            $schema = $this->identifier(trim((string) $schema, " \t\"'"));
            foreach ([
                'GRANT USAGE ON SCHEMA '.$schema.' TO '.$role,
                'REVOKE ALL ON ALL TABLES IN SCHEMA '.$schema.' FROM PUBLIC',
                'REVOKE ALL ON ALL SEQUENCES IN SCHEMA '.$schema.' FROM PUBLIC',
                'REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA '.$schema.' FROM PUBLIC',
                'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA '.$schema.' TO '.$role,
                'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA '.$schema.' TO '.$role,
                'GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA '.$schema.' TO '.$role,
            ] as $sql) {
                $database->getPdo()->exec($sql);
            }
        }
        foreach (['migrations', 'control_plane_migrations', 'core_module_migrations'] as $history) {
            $this->protectHistory($migration, $runtime, $history);
        }
    }

    public function protectHistory(string $migration, string $runtime, string $table): void
    {
        if ($migration === $runtime || ! DB::connection($migration)->getSchemaBuilder()->hasTable($table)) {
            return;
        }
        $database = DB::connection($migration);
        $role = $this->runtimeRole($runtime);
        $name = $this->identifier($table);
        $database->getPdo()->exec('REVOKE ALL ON TABLE '.$name.' FROM '.$role);
        $database->getPdo()->exec('GRANT SELECT ON TABLE '.$name.' TO '.$role);
        $row = $database->selectOne('select pg_get_serial_sequence(?, ?) as sequence', [$table, 'id']);
        if (is_string($row?->sequence)) {
            $sequence = implode('.', array_map(fn (string $part): string => $this->identifier(trim($part, '"')), explode('.', $row->sequence)));
            $database->getPdo()->exec('REVOKE ALL ON SEQUENCE '.$sequence.' FROM '.$role);
            $database->getPdo()->exec('GRANT SELECT ON SEQUENCE '.$sequence.' TO '.$role);
        }
    }

    private function runtimeRole(string $runtime): string
    {
        return $this->identifier((string) ($this->connections->configuration($runtime)['username'] ?? ''));
    }

    private function identifier(string $value): string
    {
        if ($value === '' || str_contains($value, "\0")) {
            throw new RuntimeException('Identifier privilege database tidak sah.');
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
