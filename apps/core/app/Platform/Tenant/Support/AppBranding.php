<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Support;

use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantBranding;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logo aplikasi yang berlaku untuk satu permintaan: milik tenant bila tenant itu mengunggah
 * penggantinya, selain itu logo bawaan produk.
 *
 * Dipakai tiga tempat yang harus selalu sepakat: header aplikasi, ikon tab peramban, dan gambar
 * pratinjau ketika alamat aplikasi dibagikan. Ketiganya menunjuk satu alamat, `/app-logo`, jadi
 * tidak ada tempat keempat yang bisa tertinggal memakai logo lama.
 *
 * ## Tenant mana yang dimaksud
 *
 * Pratinjau link dan halaman masuk diminta tanpa sesi, jadi tenant tidak selalu bisa dibaca dari
 * pengguna. Urutannya:
 *
 * 1. tenant pemilik alamat (alamat per tenant di SaaS),
 * 2. tenant keanggotaan yang sedang aktif (pengguna yang sudah masuk),
 * 3. satu-satunya tenant pemasangan ini (on-prem dan pengembangan satu alamat).
 *
 * Di alamat bersama dengan lebih dari satu tenant dan tanpa sesi, tidak ada jawaban yang benar;
 * logo bawaan dipakai daripada menebak milik tenant lain.
 */
class AppBranding
{
    public const DEFAULT_LOGO = 'brand/coreerp-logo.png';

    /** @var array<string, ?TenantBranding> */
    private array $rows = [];

    public function __construct(private readonly CurrentWorkspace $workspace) {}

    /** Alamat logo yang berlaku; memuat versi hanya bila tenant punya logo sendiri. */
    public function logoUrl(Request $request): string
    {
        $branding = $this->current($request);

        return $branding?->logo_path
            ? route('app-logo', ['v' => substr((string) $branding->logo_hash, 0, 12)], false)
            : '/'.self::DEFAULT_LOGO;
    }

    public function hasCustomLogo(Request $request): bool
    {
        return (bool) $this->current($request)?->logo_path;
    }

    public function current(Request $request): ?TenantBranding
    {
        $tenantId = $this->tenantId($request);

        return $tenantId === null ? null : $this->forTenant($tenantId);
    }

    public function forTenant(string $tenantId): ?TenantBranding
    {
        if (! array_key_exists($tenantId, $this->rows)) {
            $this->rows[$tenantId] = TenantBranding::query()->where('tenant_id', $tenantId)->first();
        }

        return $this->rows[$tenantId];
    }

    public function saveLogo(string $tenantId, UploadedFile $file): TenantBranding
    {
        $previous = $this->forTenant($tenantId)?->logo_path;
        $path = "branding/{$tenantId}/".Str::ulid().'.'.$file->extension();
        $content = (string) file_get_contents($file->getRealPath());
        $this->disk()->put($path, $content);

        $branding = TenantBranding::query()->updateOrCreate(['tenant_id' => $tenantId], [
            'logo_path' => $path,
            'logo_mime' => $file->getMimeType(),
            'logo_hash' => hash('sha256', $content),
        ]);
        if ($previous !== null) {
            $this->disk()->delete($previous);
        }

        return $this->rows[$tenantId] = $branding;
    }

    public function removeLogo(string $tenantId): void
    {
        $branding = $this->forTenant($tenantId);
        if ($branding?->logo_path === null) {
            return;
        }
        $path = $branding->logo_path;
        $branding->update(['logo_path' => null, 'logo_mime' => null, 'logo_hash' => null]);
        $this->disk()->delete($path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk();
    }

    private function tenantId(Request $request): ?string
    {
        $tenantId = $this->workspace->tenantOfAddress($request);
        if ($tenantId !== null) {
            return $tenantId;
        }
        if ($request->hasSession() && $request->user() !== null) {
            $membership = $this->workspace->membership($request);
            if ($membership !== null) {
                return (string) $membership->tenant_id;
            }
        }
        $tenants = Tenant::query()->limit(2)->pluck('id');

        return $tenants->count() === 1 ? (string) $tenants->first() : null;
    }
}
