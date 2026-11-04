<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Modules;

use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\ChangeLog\Support\ChangeLogSwitch;
use App\Platform\Environment\Listeners\ResetDatabaseConnections;
use App\Platform\Environment\Support\EnvironmentConnection;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Modules\Support\TenantScope;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\CurrentApplication;
use Tests\TestCase;

/**
 * Peran web berjalan di Octane: aplikasi di-boot sekali per worker, lalu setiap permintaan dilayani
 * salinan container-nya (`clone $app`, dipasang lewat `CurrentApplication::set`) dengan config yang juga
 * disalin baru. Test di sini meniru persis langkah itu untuk hal-hal yang dulu hanya benar karena Apache
 * membangun ulang segalanya per permintaan.
 */
final class OctaneWorkerStateTest extends TestCase
{
    public function test_tenant_yang_diikat_penjalan_dari_boot_terbaca_oleh_permintaan(): void
    {
        // Registry yang dibangun saat boot memegang penjalan yang di-resolve dari container induk.
        $runner = $this->app->make(TenantRunner::class);

        $tenant = $this->dalamPermintaanOctane(fn () => $runner->runFor('01TENANTOCTANE000000000000', fn (): string => TenantScope::activeTenant()));

        $this->assertSame('01TENANTOCTANE000000000000', $tenant);
        $this->assertFalse($this->app->bound(TenantScope::KEY) && is_string($this->app->make(TenantScope::KEY)), 'Tenant tidak boleh tertinggal di container induk.');
    }

    /**
     * Satu worker melayani banyak environment. Koneksi environment yang tidak ditutup di akhir permintaan
     * menumpuk satu per environment per worker sampai PostgreSQL kehabisan slot koneksi.
     */
    public function test_koneksi_environment_ditutup_di_akhir_permintaan_koneksi_bawaan_tetap_hidup(): void
    {
        $this->assertContains(ResetDatabaseConnections::class, config('octane.listeners.'.OperationTerminated::class));

        try {
            config(['database.connections.environment_octane_test' => app(EnvironmentConnection::class)->configuration((string) config('database.default'))]);
            DB::connection('environment_octane_test')->select('select 1');
            $bawaan = DB::connection()->getPdo();

            app(ResetDatabaseConnections::class)->handle(new \stdClass);

            $this->assertArrayNotHasKey('environment_octane_test', DB::getConnections());
            $this->assertSame($bawaan, DB::connection()->getPdo(), 'Koneksi bawaan dipakai ulang, bukan dibuka lagi.');
        } finally {
            DB::purge('environment_octane_test');
        }
    }

    /**
     * Pelaku kolom jejak dipasang tiap kali guard memegang pengguna dan baru dilepas saat logout; saklar
     * log perubahan dimatikan migration. Keduanya variabel sesi di koneksi yang dipakai ulang worker, jadi
     * tanpa pelepasan permintaan berikutnya — yang bisa tanpa pengguna atau milik tenant lain — mencatat
     * orang yang salah sebagai pembuat data, atau tidak tercatat di log perubahan sama sekali.
     */
    public function test_variabel_sesi_database_dilepas_di_akhir_permintaan(): void
    {
        AuditActor::set(42);
        ChangeLogSwitch::pause();
        $bawaan = DB::connection()->getPdo();

        app(ResetDatabaseConnections::class)->handle(new \stdClass);

        $nilai = DB::selectOne("select coalesce(current_setting('".AuditActor::SETTING."', true), '') as pelaku, coalesce(current_setting('".ChangeLogSwitch::SETTING."', true), '') as saklar");
        $this->assertSame('', $nilai->pelaku);
        $this->assertSame('', $nilai->saklar);
        $this->assertSame($bawaan, DB::connection()->getPdo());
    }

    public function test_koneksi_dengan_transaksi_tertinggal_ditutup_bukan_dipakai_ulang(): void
    {
        try {
            config(['database.connections.pgsql_octane_test' => app(EnvironmentConnection::class)->configuration((string) config('database.default'))]);
            DB::connection('pgsql_octane_test')->beginTransaction();

            app(ResetDatabaseConnections::class)->handle(new \stdClass);

            $this->assertArrayNotHasKey('pgsql_octane_test', DB::getConnections());
        } finally {
            DB::purge('pgsql_octane_test');
        }
    }

    public function test_sync_job_cleanup_preserves_the_callers_transaction_and_pdo(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        $pdo = $connection->getPdo();
        $job = new SyncJob($this->app, json_encode(['displayName' => 'fixture'], JSON_THROW_ON_ERROR), 'sync', 'default');
        try {
            app(ResetDatabaseConnections::class)->handle(new JobProcessed('sync', $job));
            $this->assertSame($pdo, $connection->getRawPdo());
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    public function test_worker_cleanup_only_rolls_back_its_own_transaction_and_restores_the_caller_actor(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        AuditActor::set(42);
        $pdo = $connection->getPdo();
        $job = \Mockery::mock(Job::class);
        $cleanup = app(ResetDatabaseConnections::class);
        try {
            $cleanup->begin(new JobProcessing('database', $job));
            $connection->beginTransaction();
            AuditActor::set(71);
            $cleanup->handle(new JobProcessed('database', $job));
            $this->assertSame($pdo, $connection->getRawPdo());
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame('42', $connection->selectOne("select current_setting('coreerp.user_id', true) as actor")->actor);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            AuditActor::clear();
        }
    }

    public function test_worker_cleanup_preserves_a_caller_frame_without_a_transaction(): void
    {
        $connections = app(EnvironmentConnection::class);
        try {
            $connections->runWithin(null, function () use ($connections): void {
                AuditActor::set(42);
                ChangeLogSwitch::pause();
                $connection = DB::connection();
                $pdo = $connection->getPdo();
                $job = \Mockery::mock(Job::class);
                $cleanup = app(ResetDatabaseConnections::class);
                $cleanup->begin(new JobProcessing('database', $job));
                AuditActor::set(71);
                ChangeLogSwitch::resume();
                $cleanup->handle(new JobProcessed('database', $job));
                $cleanup->handle(new JobExceptionOccurred('database', $job, new \RuntimeException('after-listener fixture failure')));
                $this->assertSame($pdo, $connection->getRawPdo());
                $this->assertSame(1, $connections->depth());
                $state = $connection->selectOne("select current_setting('coreerp.user_id', true) as actor, current_setting('coreerp.change_log', true) as change_log");
                $this->assertSame('42', $state->actor);
                $this->assertSame('off', $state->change_log);
            });
        } finally {
            AuditActor::clear();
            ChangeLogSwitch::resume();
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $permintaan
     * @return T
     */
    private function dalamPermintaanOctane(callable $permintaan): mixed
    {
        $induk = $this->app;
        $salinan = clone $induk;
        CurrentApplication::set($salinan);

        try {
            return $permintaan();
        } finally {
            CurrentApplication::set($induk);
        }
    }
}
