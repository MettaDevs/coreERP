<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\Environment;
use App\Models\TenantIdentityProvider;
use Illuminate\Http\Request;

/**
 * Apakah tenant ini masuk lewat penyedia identitas bersama.
 *
 * Syarat pertama tidak pernah berubah: penempatan ini harus menyetel penyedianya. Tanpa
 * `COREERP_SSO_ISSUER`, `_CLIENT_ID`, dan `_CLIENT_SECRET` tidak ada penyedia untuk dituju, dan
 * jawabannya selalu tidak — termasuk di setiap server klien on-prem, yang templat env-nya memang
 * tidak memuat ketiganya sama sekali.
 *
 * ## Syarat kedua: tabelnya mencatat pengecualian, bukan izin
 *
 * Tenant **tanpa baris** mengikuti bawaan penempatan, yaitu menyala. Yang menjadikan sebuah tenant
 * tidak memakai SSO adalah baris yang mengatakannya — `mode` selain `bersama`, atau `aktif` yang
 * mati.
 *
 * Arah ini dibalik dengan sengaja, dan alasannya diukur. Sebelumnya setiap tenant harus ditulis
 * barisnya satu per satu lewat `tenant:sso`, sementara layar setelannya belum ada; akibatnya pada
 * penempatan yang penyedianya sudah disetel penuh, nol tenant memakai SSO — bukan karena ada yang
 * memutuskan begitu, melainkan karena tidak ada yang menjalankan perintahnya. Bawaan yang menuntut
 * perintah manual untuk setiap tenant baru adalah bawaan yang akan terlupakan, dan yang
 * terlupakan tampil sebagai "SSO tidak jalan".
 *
 * Ongkosnya jujur: isi tabel ini tidak lagi cukup untuk menyimpulkan siapa memakai SSO — pembacanya
 * harus tahu env penempatannya juga. Karena itu layar setelan yang kelak dibuat wajib menampilkan
 * keadaan **efektif** dari `availableFor()`, bukan membaca barisnya sendiri.
 *
 * Yang tidak ikut berubah: menyala berarti ada tombol SSO di samping formulir kata sandi, bukan
 * kata sandi yang tertutup.
 */
class TenantSso
{
    public function __construct(private readonly SharedIdentityProvider $provider) {}

    public function availableFor(string $tenantId): bool
    {
        if (! $this->provider->isConfigured()) {
            return false;
        }

        $setelan = TenantIdentityProvider::query()->where('tenant_id', $tenantId)->first();

        // Tidak ada baris berarti tenant ini belum pernah memutuskan apa pun, dan yang berlaku
        // adalah keputusan penempatan. Baris yang ada selalu menang — termasuk mode `sendiri`,
        // yang berarti tenant ini memakai penyedianya sendiri dan bukan yang bersama ini.
        return $setelan === null
            || ($setelan->mode === 'bersama' && $setelan->aktif);
    }

    /** Alamat tombol masuk lewat SSO di halaman masuk, atau null bila alamat ini tidak menawarkannya. */
    public function loginUrlFor(Request $request): ?string
    {
        $environment = $request->attributes->get('coreerp.environment');

        return $environment instanceof Environment && $this->availableFor($environment->tenant_id)
            ? '/sso/masuk'
            : null;
    }
}
