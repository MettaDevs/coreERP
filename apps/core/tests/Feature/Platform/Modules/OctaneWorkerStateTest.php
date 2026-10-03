<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Modules;

use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\ChangeLog\Support\ChangeLogSwitch;
use App\Platform\Environment\Listeners\ResetDatabaseConnections;
use App\Platform\Environment\Support\EnvironmentConnection;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Modules\Support\TenantScope;
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
            app(EnvironmentConnection::class)->register('environment_octane_test', DB::connection()->getDatabaseName());
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
            app(EnvironmentConnection::class)->register('pgsql_octane_test', DB::connection()->getDatabaseName());
            DB::connection('pgsql_octane_test')->beginTransaction();

            app(ResetDatabaseConnections::class)->handle(new \stdClass);

            $this->assertArrayNotHasKey('pgsql_octane_test', DB::getConnections());
        } finally {
            DB::purge('pgsql_octane_test');
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
