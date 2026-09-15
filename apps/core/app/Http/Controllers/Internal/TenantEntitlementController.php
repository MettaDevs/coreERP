<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantAppEntitlement;
use Illuminate\Http\JsonResponse;

/**
 * App yang sedang berhak dipakai sebuah tenant, dibaca admin.erp saat menerbitkan lisensi situs.
 *
 * ## Kenapa admin.erp bertanya ke sini, bukan membaca tabelnya sendiri
 *
 * Karena `tenant_app_entitlements` milik Core, dan satu tempat mengubah app yang dibeli — sama dengan
 * SaaS — hanya bertahan selama hanya ada satu tempat yang membacanya sebagai kebenaran. Lisensi yang
 * disusun admin.erp dari salinannya sendiri akan menyimpang dari hak yang sebenarnya pada hari pertama
 * seseorang menambah app lewat Core.
 *
 * ## "Sedang berhak" berarti tiga syarat sekaligus
 *
 * Status `active`, sudah mulai (`starts_at` kosong atau tidak di masa depan), dan belum berakhir
 * (`ends_at` kosong atau di masa depan). Status saja tidak cukup: hak yang berakhir kemarin masih
 * berstatus `active` sampai ada yang mengubahnya, dan lisensi 30 hari yang mencantumkannya akan
 * membukakan app itu sebulan lebih lama daripada yang dibayar.
 *
 * Daftarnya unik dan terurut, karena itu yang dituntut kontrak berkas lisensi. Menyusunnya di sini
 * berarti penerbit tidak perlu mengulang aturan itu, dan dua penerbitan atas hak yang sama memuat
 * daftar yang sama persis — perbedaan yang hanya berupa urutan tidak pernah terbaca sebagai perubahan.
 * Keunikannya dijamin indeks unik `(tenant_id, app_id)` di tabelnya, bukan oleh `DISTINCT` di sini:
 * satu tenant memang tidak dapat berhak atas satu app dua kali.
 */
final class TenantEntitlementController extends Controller
{
    public function show(string $tenant): JsonResponse
    {
        $found = Tenant::query()->whereKey($tenant)->first();

        if ($found === null) {
            return response()->json(['message' => 'Tenant itu tidak ada di Core.'], 404);
        }

        $now = now();

        $apps = TenantAppEntitlement::query()
            ->where('tenant_id', $found->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('app_id')
            ->pluck('app_id')
            ->map(fn (mixed $appId): string => (string) $appId)
            ->values()
            ->all();

        return response()->json([
            'tenant_id' => $found->id,
            'apps' => $apps,
        ]);
    }
}
