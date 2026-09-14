<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\Environment;
use App\Models\TenantIdentityProvider;
use Illuminate\Http\Request;

/**
 * Apakah tenant ini masuk lewat penyedia identitas bersama.
 *
 * Dua syarat, dan keduanya wajib: penempatan ini menyetel penyedianya, dan tenant ini memilih mode
 * `bersama` yang aktif. Salah satunya saja berarti tidak ada tombol SSO — bukan tombol yang gagal
 * sesudah ditekan.
 */
class TenantSso
{
    public function __construct(private readonly SharedIdentityProvider $provider) {}

    public function availableFor(string $tenantId): bool
    {
        return $this->provider->isConfigured()
            && TenantIdentityProvider::query()
                ->where('tenant_id', $tenantId)
                ->where('mode', 'bersama')
                ->where('aktif', true)
                ->exists();
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
