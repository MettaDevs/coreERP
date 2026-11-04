<?php

declare(strict_types=1);

namespace App\Platform\Environment\Support;

use App\Platform\Environment\Models\Environment;
use Closure;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class EnvironmentConnection
{
    public const MAINTENANCE = 'environment_maintenance';

    /** @var list<ConnectionFrame> */
    private array $frames = [];

    public function controlPlane(): string
    {
        $connection = $this->configured('control_connection');
        if ($connection === null && $this->split()) {
            throw new RuntimeException('Koneksi control belum dikonfigurasi.');
        }

        return $connection ?? $this->business();
    }

    public function business(): string
    {
        $connection = $this->configured('business_connection');
        if ($connection === null && $this->split()) {
            throw new RuntimeException('Koneksi bisnis belum dikonfigurasi.');
        }

        return $connection ?? (string) config('database.default');
    }

    public function for(?Environment $environment): string
    {
        $database = $environment?->database_name;

        return is_string($database) && $database !== ''
            ? $this->register('environment_'.$environment->id, $database)
            : $this->business();
    }

    public function register(string $name, string $database): string
    {
        $this->assertEnvironmentDatabase($database);
        if (in_array($name, [$this->controlPlane(), $this->business(), $this->configured('control_migration_connection'), $this->configured('business_migration_connection'), $this->configured('maintenance_connection')], true)) {
            throw new RuntimeException('Alias koneksi authority tidak boleh diganti dengan database environment.');
        }

        return $this->registerFrom($name, $database, $this->business());
    }

    public function assertEnvironmentDatabase(string $database): void
    {
        if (preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_-]{0,62}$/', $database) !== 1) {
            throw new RuntimeException('Nama database environment tidak sah.');
        }
        $reserved = [(string) config('coreerp.database')];
        foreach (array_filter([$this->controlPlane(), $this->business(), $this->configured('maintenance_connection')]) as $name) {
            $reserved[] = (string) ($this->configuration($name)['database'] ?? '');
        }
        if (in_array($database, $reserved, true)) {
            throw new RuntimeException('Database authority atau pooled bukan database dedicated environment.');
        }
    }

    public function migrationFor(?string $runtime = null): string
    {
        $runtime ??= $this->business();
        $this->assertBusiness($runtime);
        $migration = $this->configured('business_migration_connection');

        if ($migration === null) {
            if ($this->split()) {
                throw new RuntimeException('Koneksi migration bisnis belum dikonfigurasi.');
            }

            return $runtime;
        }

        $configuration = $this->configuration($runtime);
        $owner = $this->configuration($migration);

        if ($this->split() && ($owner['username'] ?? null) === ($this->configuration($this->business())['username'] ?? null)) {
            throw new RuntimeException('Role runtime dan migration bisnis harus berbeda.');
        }

        if (($configuration['host'] ?? null) !== ($owner['host'] ?? null) || (string) ($configuration['port'] ?? '') !== (string) ($owner['port'] ?? '')) {
            throw new RuntimeException('Koneksi runtime dan migration bisnis harus menunjuk server yang sama.');
        }

        if ($this->endpoint($configuration) === $this->endpoint($owner)) {
            return $migration;
        }

        return $this->registerFrom($runtime.'_migration', (string) $configuration['database'], $migration);
    }

    public function controlMigration(): string
    {
        if (! $this->split()) {
            throw new RuntimeException('Bootstrap control terpisah hanya tersedia dalam mode split. On-prem gabungan memakai migrate bisnis.');
        }

        $migration = $this->configured('control_migration_connection');
        $control = $this->configuration($this->controlPlane());

        if ($this->endpoint($control) === $this->endpoint($this->configuration($this->business()))) {
            throw new RuntimeException('Mode split memerlukan database control dan bisnis yang berbeda.');
        }

        if ($migration === null || $this->endpoint($this->configuration($migration)) !== $this->endpoint($control)) {
            throw new RuntimeException('Koneksi migration control harus menunjuk database control yang dikonfigurasi.');
        }

        if (($this->configuration($migration)['username'] ?? null) === ($control['username'] ?? null)) {
            throw new RuntimeException('Role runtime dan migration control harus berbeda.');
        }

        return $migration;
    }

    public function releaseMigrationFor(string $runtime): void
    {
        if (array_any($this->frames, fn (ConnectionFrame $frame): bool => $frame->target === $runtime)) {
            return;
        }
        $name = $runtime.'_migration';
        if ($name !== $this->configured('business_migration_connection') && (DB::getConnections()[$name] ?? null)?->transactionLevel() === 0) {
            DB::purge($name);
        }
    }

    public function maintenance(): string
    {
        $maintenance = $this->configured('maintenance_connection');

        if ($maintenance !== null) {
            return $maintenance;
        }

        if ($this->split()) {
            throw new RuntimeException('Koneksi provisioning belum dikonfigurasi.');
        }

        return $this->registerFrom(self::MAINTENANCE, (string) $this->configuration($this->business())['database'], $this->business());
    }

    public function prepareProvisionedDatabase(string $database): void
    {
        $this->assertEnvironmentDatabase($database);
        if (! $this->split() && $this->configured('business_migration_connection') === null) {
            return;
        }
        $runtime = $this->configuration($this->business());
        $migration = $this->configuration($this->migrationFor());
        $name = $this->registerFrom('environment_database_access', $database, $this->maintenance());
        try {
            $pdo = DB::connection($name)->getPdo();
            $target = $this->identifier($database);
            $runtimeRole = $this->identifier((string) ($runtime['username'] ?? ''));
            $migrationRole = $this->identifier((string) ($migration['username'] ?? ''));
            $pdo->exec('REVOKE ALL ON DATABASE '.$target.' FROM PUBLIC');
            $pdo->exec('GRANT CONNECT ON DATABASE '.$target.' TO '.$runtimeRole.', '.$migrationRole);
            $pdo->exec('GRANT CREATE ON DATABASE '.$target.' TO '.$migrationRole);
            $pdo->exec('REVOKE ALL ON SCHEMA public FROM PUBLIC');
            $pdo->exec('GRANT USAGE, CREATE ON SCHEMA public TO '.$migrationRole.' WITH GRANT OPTION');
            $pdo->exec('GRANT USAGE ON SCHEMA public TO '.$runtimeRole);
        } finally {
            DB::purge($name);
        }
    }

    /** @return array<string, mixed> */
    public function configuration(string $name): array
    {
        $configuration = config('database.connections.'.$name);

        if (! is_array($configuration)) {
            throw new RuntimeException(sprintf('Koneksi "%s" belum dikonfigurasi.', $name));
        }

        return (new ConfigurationUrlParser)->parseConfiguration($configuration);
    }

    public function assertBusiness(string $name): void
    {
        $business = $this->endpoint($this->configuration($this->business()));
        $control = $this->endpoint($this->configuration($this->controlPlane()));
        $target = $this->endpoint($this->configuration($name));

        if ($this->split() && $business === $control) {
            throw new RuntimeException('Mode split memerlukan database control dan bisnis yang berbeda.');
        }

        if ($target === $control && ($this->split() || $control !== $business)) {
            throw new RuntimeException('Migration bisnis atau module tidak boleh dijalankan pada database control.');
        }
    }

    public function createSchemas(string $connection): void
    {
        $configuration = $this->configuration($connection);
        $search = $configuration['search_path'] ?? 'public';
        $schemas = is_array($search) ? $search : explode(',', (string) $search);

        foreach ($schemas as $schema) {
            $clean = trim((string) $schema, " \t\"'");
            if ($clean === '' || $clean === 'public') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_]*$/', $clean) !== 1) {
                throw new RuntimeException('Nama schema koneksi tidak sah.');
            }

            DB::connection($connection)->statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $clean));
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runWithin(?Environment $environment, Closure $callback): mixed
    {
        $frame = $this->enter($environment);

        try {
            return $callback();
        } finally {
            $this->leave($frame);
        }
    }

    public function useForRequest(Environment $environment): void
    {
        $this->enter($environment);
    }

    /** @internal */
    public function depth(): int
    {
        return count($this->frames);
    }

    public function reset(int $depth = 0): void
    {
        while (count($this->frames) > $depth) {
            $this->restore(array_pop($this->frames));
        }
    }

    private function enter(?Environment $environment): ConnectionFrame
    {
        $control = $this->controlPlane();
        $business = $this->business();
        $target = $this->for($environment);
        $pins = $this->pins($target, $control, $business);
        $snapshot = [];
        foreach (array_keys($pins) as $key) {
            $snapshot[$key] = config($key);
        }

        $container = app();
        $hadEnvironment = $container->bound(ActiveEnvironment::KEY);
        $previous = $hadEnvironment ? $container->make(ActiveEnvironment::KEY) : null;
        $existing = DB::getConnections()[$target] ?? null;
        $migration = DB::getConnections()[$target.'_migration'] ?? null;
        $ancestor = array_any($this->frames, fn (ConnectionFrame $frame): bool => $frame->target === $target);
        $dedicated = $target !== $business && str_starts_with($target, 'environment_');
        $frame = new ConnectionFrame(
            $snapshot,
            $target,
            $dedicated && ! $ancestor && ! ($existing?->getRawPdo() instanceof PDO),
            $dedicated && ! $ancestor && $target.'_migration' !== $this->configured('business_migration_connection') && ! ($migration?->getRawPdo() instanceof PDO),
            $hadEnvironment,
            is_string($previous) ? $previous : null,
        );
        $this->frames[] = $frame;
        config($pins);
        $container->instance(ActiveEnvironment::KEY, $environment->id ?? false);
        $container->make(ActiveEnvironment::class)->forget();

        return $frame;
    }

    private function leave(ConnectionFrame $frame): void
    {
        if (array_pop($this->frames) !== $frame) {
            throw new RuntimeException('Urutan penutupan context database tidak sah.');
        }

        $this->restore($frame);
    }

    private function restore(ConnectionFrame $frame): void
    {
        config($frame->configuration);
        if ($frame->hadEnvironment) {
            app()->instance(ActiveEnvironment::KEY, $frame->environmentId ?? false);
        } else {
            app()->forgetInstance(ActiveEnvironment::KEY);
        }
        app(ActiveEnvironment::class)->forget();
        if ($frame->disconnect) {
            DB::purge($frame->target);
        }
        if ($frame->disconnectMigration) {
            DB::purge($frame->target.'_migration');
        }
    }

    /** @return array<string, string> */
    private function pins(string $target, string $control, string $business): array
    {
        return [
            'database.default' => $target,
            'coreerp.control_connection' => $control,
            'coreerp.business_connection' => $business,
        ];
    }

    private function registerFrom(string $name, string $database, string $base): string
    {
        if (preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_-]{0,62}$/', $database) !== 1) {
            throw new RuntimeException('Nama database environment tidak sah.');
        }

        $configuration = $this->configuration($base);
        $configuration['database'] = $database;
        $configuration['url'] = null;
        foreach (['read', 'write'] as $direction) {
            if (isset($configuration[$direction]) && is_array($configuration[$direction])) {
                $configuration[$direction]['database'] = $database;
                $configuration[$direction]['url'] = null;
            }
        }
        if (config('database.connections.'.$name) !== $configuration) {
            $existing = DB::getConnections()[$name] ?? null;
            if ($existing !== null && $existing->transactionLevel() > 0) {
                throw new RuntimeException('Koneksi yang masih bertransaksi tidak boleh diganti.');
            }
            config(['database.connections.'.$name => $configuration]);
            DB::purge($name);
        }

        return $name;
    }

    private function configured(string $key): ?string
    {
        $name = config('coreerp.'.$key);

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function identifier(string $value): string
    {
        if ($value === '' || str_contains($value, "\0")) {
            throw new RuntimeException('Identifier akses database tidak sah.');
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    private function split(): bool
    {
        return match (config('coreerp.database_mode', 'combined')) {
            'combined' => false,
            'split' => true,
            default => throw new RuntimeException('Mode database harus combined atau split.'),
        };
    }

    /** @param array<string, mixed> $configuration */
    private function endpoint(array $configuration): string
    {
        return json_encode([
            (string) ($configuration['driver'] ?? ''),
            (string) ($configuration['host'] ?? ''),
            (string) ($configuration['port'] ?? '5432'),
            (string) ($configuration['database'] ?? ''),
        ], JSON_THROW_ON_ERROR);
    }
}
