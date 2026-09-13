<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantIdentityProvider;
use App\Support\Sso\SharedIdentityProvider;
use Illuminate\Console\Command;

/**
 * Menyalakan atau mematikan masuk lewat penyedia identitas bersama untuk satu tenant.
 *
 * Perintah, bukan layar, dan itu sementara: layar setelan penyedia identitas di konsol belum ada,
 * dan tanpa jalan apa pun untuk menulis barisnya, SSO tidak dapat dicoba di server dev sama sekali.
 *
 * Menyalakan tidak menutup kata sandi. Tenant bermode `bersama` tetap menampilkan formulir kata
 * sandi di bawah tombol SSO — menutupnya keputusan tersendiri yang belum diambil.
 */
class ConfigureTenantSso extends Command
{
    protected $signature = 'tenant:sso
        {tenant : Slug tenant}
        {--matikan : Kembali ke kata sandi saja}';

    protected $description = 'Nyalakan atau matikan masuk lewat penyedia identitas bersama untuk satu tenant';

    public function handle(SharedIdentityProvider $provider): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();

        if (! $tenant instanceof Tenant) {
            $this->error(sprintf('Tenant "%s" tidak ada.', (string) $this->argument('tenant')));

            return self::FAILURE;
        }

        if ($this->option('matikan')) {
            TenantIdentityProvider::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                ['mode' => 'lokal', 'protokol' => null, 'aktif' => false],
            );

            $this->info(sprintf('%s kembali masuk dengan kata sandi saja.', $tenant->name));

            return self::SUCCESS;
        }

        // Ditolak, bukan disimpan lalu tidak berfungsi: baris aktif tanpa penyedia yang disetel
        // terbaca sebagai "SSO sudah menyala" oleh siapa pun yang melihat daftarnya.
        if (! $provider->isConfigured()) {
            $this->error('Penyedia identitas bersama belum disetel: COREERP_SSO_ISSUER, COREERP_SSO_CLIENT_ID, dan COREERP_SSO_CLIENT_SECRET wajib terisi.');

            return self::FAILURE;
        }

        TenantIdentityProvider::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['mode' => 'bersama', 'protokol' => 'oidc', 'aktif' => true],
        );

        $this->info(sprintf('%s kini menampilkan tombol masuk lewat SSO (%s).', $tenant->name, $provider->issuer()));

        return self::SUCCESS;
    }
}
