<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Tenant\Support\AppBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo aplikasi milik tenant: layar pengaturannya, unggah dan pencabutannya, serta berkas
 * gambarnya sendiri.
 *
 * Berkasnya dilayani tanpa login. Halaman masuk dan pratinjau link memintanya sebelum ada sesi,
 * dan logo sebuah usaha memang bukan rahasia.
 */
class AppBrandingController extends Controller
{
    public function __construct(private readonly AppBranding $branding) {}

    public function page(Request $request): InertiaResponse
    {
        $membership = $this->currentMembership($request);

        return Inertia::render('platform/tenant/app-logo', [
            'hasCustomLogo' => (bool) $this->branding->forTenant((string) $membership->tenant_id)?->logo_path,
            'canManage' => $membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE),
        ]);
    }

    public function show(Request $request): Response
    {
        $branding = $this->branding->current($request);
        if ($branding?->logo_path === null || ! $this->branding->disk()->exists($branding->logo_path)) {
            return redirect('/'.AppBranding::DEFAULT_LOGO);
        }

        return $this->branding->disk()->response($branding->logo_path, null, [
            'Content-Type' => $branding->logo_mime ?? 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $request->validate([
            // PNG dan JPEG saja: SVG dapat membawa skrip. Batas ukurannya kecil karena aplikasi
            // pesan menolak gambar pratinjau link yang besar.
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:512'],
        ]);
        $this->branding->saveLogo((string) $membership->tenant_id, $request->file('file'));

        return response()->json(['data' => ['logo_url' => $this->branding->logoUrl($request)]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->branding->removeLogo((string) $membership->tenant_id);

        return response()->json(['data' => ['logo_url' => '/'.AppBranding::DEFAULT_LOGO]]);
    }
}
