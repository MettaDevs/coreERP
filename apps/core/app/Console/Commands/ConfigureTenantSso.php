<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantIdentityProvider;
use App\Support\Sso\SharedIdentityProvider;
use Illuminate\Console\Command;

/**
 * Mengecualikan satu tenant dari bawaan penempatan — atau mengembalikannya.
 *
 * Yang dipakai sehari-hari `--matikan`. Sejak `TenantSso` membaca tenant tanpa baris sebagai
 * "ikut bawaan penempatan", menyalakan SSO tidak lagi menuntut perintah apa pun: penempatan yang
 * menyetel `COREERP_SSO_*` sudah menyalakannya untuk seluruh tenantnya. Yang tersisa bagi perintah
 * ini adalah menuliskan **keputusan sebaliknya**.
 *
 * Bentuk tanpa `--matikan` tetap ada untuk mencabut pengecualian itu. Ia menulis baris `bersama`
 * yang eksplisit, yang hasilnya sama dengan tidak ada baris sama sekali — dan memang begitu
 * seharusnya, karena satu-satunya beda di antara keduanya adalah apakah keputusannya pernah
 * diucapkan.
 *
 * Perintah, bukan layar, dan itu masih sementara: layar setelan penyedia identitas di konsol belum
 * ada. Saat ia dibuat, yang ditampilkannya wajib keadaan efektif dari `TenantSso::availableFor()`,
 * bukan isi baris ini — sebuah tenant dapat memakai SSO tanpa punya baris.
 *
 * Menyalakan tidak menutup kata sandi. Tenant bermode `bersama` tetap menampilkan formulir kata
 * sandi di bawah tombol SSO — menutupnya keputusan tersendiri yang belum diambil.
 */
class ConfigureTenantSso extends Command
{
    protected $signature = 'tenant:sso
        {tenant : Slug tenant}
        {--matikan : Kecualikan tenant ini — kata sandi saja, walau penempatannya memakai SSO}';

    protected $description = 'Kecualikan satu tenant dari bawaan penempatan, atau kembalikan ke bawaan';

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
