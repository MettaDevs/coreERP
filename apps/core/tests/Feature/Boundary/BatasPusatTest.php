<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Models\Client;
use App\Models\ConsoleSetting;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\OperatorAuditEvent;
use App\Models\Organization;
use App\Models\ProviderAccess;
use App\Models\Site;
use App\Models\SiteEnrollmentToken;
use App\Models\SiteOperation;
use App\Models\SiteRelease;
use App\Models\SiteReport;
use App\Models\Tenant;
use App\Models\TenantIdentityProvider;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Batas antara sisi pusat dan sisi environment benar-benar tersambung, bukan sekadar ditulis.
 *
 * Dua hal yang dijaga di sini, dan keduanya baru berarti kelak ketika kedua sisi benar-benar
 * terpisah — justru karena itu ia dijaga sekarang, selagi salahnya masih gratis untuk diperbaiki.
 *
 * Yang pertama: setiap tabel yang disepakati milik sisi pusat memang memakai penandanya. Daftar di
 * bawah ditulis tangan dengan sengaja. Ia bukan hal yang dapat diturunkan dari kode — "tabel ini
 * milik pusat" adalah keputusan desain, dan keputusan desain yang tidak ditulis di mana pun akan
 * ditebak berbeda oleh orang berikutnya.
 *
 * Yang kedua: penandanya benar-benar memindahkan koneksi ketika setelannya diisi. Trait yang
 * terpasang tetapi tidak berpengaruh adalah bentuk kegagalan yang paling mudah lolos — semuanya
 * terlihat benar, dan baru ketahuan salah pada hari sisi pusat benar-benar pindah database.
 */
class BatasPusatTest extends TestCase
{
    /** @return list<array{class-string<Model>, string}> */
    public static function modelSisiPusat(): array
    {
        return [
            [User::class, 'users'],
            [Client::class, 'clients'],
            [Tenant::class, 'tenants'],
            [TenantMembership::class, 'tenant_memberships'],
            [ProviderAccess::class, 'provider_access'],
            [Environment::class, 'environments'],
            [EnvironmentOperation::class, 'environment_operations'],
            [TenantIdentityProvider::class, 'tenant_identity_providers'],
            [Site::class, 'sites'],
            [SiteEnrollmentToken::class, 'site_enrollment_tokens'],
            [SiteOperation::class, 'site_operations'],
            [SiteReport::class, 'site_reports'],
            [SiteRelease::class, 'site_releases'],
            [ConsoleSetting::class, 'console_settings'],
            [OperatorAuditEvent::class, 'operator_audit_events'],
        ];
    }

    /**
     * @param  class-string<Model>  $kelas
     */
    #[DataProvider('modelSisiPusat')]
    public function test_model_sisi_pusat_memakai_penandanya(string $kelas, string $tabel): void
    {
        $this->assertContains(
            OwnedByControlPlane::class,
            class_uses_recursive($kelas),
            $kelas.' memegang tabel `'.$tabel.'` yang disepakati milik sisi pusat, tetapi tidak memakai OwnedByControlPlane. '
            .'Tanpa penanda itu ia akan tertinggal di database environment pada hari kedua sisi dipisah.'
        );
    }

    public function test_tanpa_setelan_koneksinya_tetap_yang_bawaan(): void
    {
        config(['coreerp.control_connection' => null]);

        $this->assertSame(
            config('database.default'),
            Environment::query()->getConnection()->getName(),
        );
    }

    public function test_dengan_setelan_koneksinya_benar_benar_berpindah(): void
    {
        config(['coreerp.control_connection' => 'pgsql_test_secondary']);

        $this->assertSame(
            'pgsql_test_secondary',
            Environment::query()->getConnection()->getName(),
            'Penanda OwnedByControlPlane terpasang tetapi tidak memindahkan koneksi. Ia tidak menjaga apa pun.'
        );
    }

    public function test_model_sisi_environment_tidak_ikut_berpindah(): void
    {
        config(['coreerp.control_connection' => 'pgsql_test_secondary']);

        // Organisasi milik tenant, jadi ia tinggal di sisi environment dan wajib tetap di koneksi
        // bawaan meski setelan pusat diisi. Kalau ia ikut berpindah, seluruh data bisnis akan
        // mencari dirinya di database yang salah.
        $this->assertSame(
            config('database.default'),
            Organization::query()->getConnection()->getName(),
        );
    }
}
