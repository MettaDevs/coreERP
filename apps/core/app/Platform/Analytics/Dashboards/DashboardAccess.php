<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Dashboards;

use App\Platform\Access\Support\CorePermissions;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Analytics\Models\Dashboard;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Aturan berbagi dasbor dan query tersimpan (`docs/todo/analitik/keamanan.md` bagian *Dasbor, widget, dan query
 * tersimpan*), meniru preset laporan K-25 (`ReportOptions`) yang sudah teruji:
 *
 * | Tindakan | Pribadi | Bersama |
 * | --- | --- | --- |
 * | Melihat | Pemiliknya | Setiap pemegang `core.analytics.dashboard.read` di tenant |
 * | Mengubah, mengarsipkan | Pemiliknya, bila memegang `core.analytics.dashboard.create` | Pemegang `core.analytics.shared-dashboard.update` |
 * | Membuat | Pemegang `core.analytics.dashboard.create` | Ditambah `core.analytics.shared-dashboard.update` |
 * | Menjadikan bersama | Pemiliknya, bila juga memegang `core.analytics.shared-dashboard.update` | — |
 *
 * `core.analytics.dashboard.read` dijaga gate rute, jadi tidak diperiksa ulang di sini. Yang tidak boleh
 * dilihat dijawab 404 seperti yang tidak ada — dasbor pribadi orang lain tidak bocor keberadaannya. Yang
 * boleh dilihat tetapi tidak boleh diubah dijawab 403 dengan alasannya, karena keberadaannya sudah diketahui.
 *
 * Hak melihat dasbor tidak pernah meminjamkan hak membaca datanya: widget dihitung sebagai yang melihat oleh
 * `WidgetDataController`, dengan permission dataset dan hibah kebijakan data milik yang melihat.
 */
final class DashboardAccess
{
    public function __construct(private readonly CorePermissions $permissions) {}

    public function mayCreate(TenantMembership $membership): bool
    {
        return $this->permissions->allows($membership, CoreSecurityCatalog::ANALYTICS_DASHBOARD_CREATE);
    }

    public function mayShare(TenantMembership $membership): bool
    {
        return $this->permissions->allows($membership, CoreSecurityCatalog::ANALYTICS_SHARED_DASHBOARD_UPDATE);
    }

    /**
     * Baris yang sampai di sini sudah dari tenant aktif: route binding mencarinya di tenant itu saja
     * (`BindsWithinActiveTenant`) dan daftar memakai {@see self::visible()}. Tenant sengaja tidak dibandingkan
     * lagi di sini, supaya penangkal lintas tenant tetap satu dan `AnalyticsTenantIsolationTest` dapat
     * membuktikannya.
     */
    public function canView(TenantMembership $membership, Dashboard|SavedQuery $item): bool
    {
        return $item->shared || $item->user_id === (int) $membership->user_id;
    }

    public function canEdit(TenantMembership $membership, Dashboard|SavedQuery $item): bool
    {
        if (! $this->canView($membership, $item)) {
            return false;
        }

        return $item->shared ? $this->mayShare($membership) : $this->mayCreate($membership);
    }

    /** Dasbor atau query tersimpan yang boleh dilihat; selain itu 404. */
    public function authorizeView(TenantMembership $membership, Dashboard|SavedQuery $item): void
    {
        abort_unless($this->canView($membership, $item), 404);
    }

    /** Yang boleh diubah dan diarsipkan; yang tidak terlihat 404, yang terlihat tetapi tidak boleh diubah 403. */
    public function authorizeEdit(TenantMembership $membership, Dashboard|SavedQuery $item): void
    {
        $this->authorizeView($membership, $item);
        abort_unless($this->canEdit($membership, $item), 403, $item->shared
            ? 'Anda belum boleh mengubah dasbor dan analisis bersama.'
            : 'Anda belum boleh mengubah dasbor dan analisis pribadi.');
    }

    /**
     * Pembuatan baru: dasbor dan query pribadi butuh hak membuat; yang bersama butuh juga hak mengelola yang
     * bersama.
     */
    public function authorizeCreate(TenantMembership $membership, bool $shared): void
    {
        abort_unless($this->mayCreate($membership), 403, 'Anda belum boleh membuat dasbor dan analisis.');
        abort_if($shared && ! $this->mayShare($membership), 403, 'Anda belum boleh membagikan dasbor dan analisis ke semua pengguna.');
    }

    /**
     * Mengganti `shared`. Menjadikan bersama hanya oleh pemiliknya yang juga memegang hak mengelola yang bersama;
     * berhenti membagikan oleh pemegang hak itu, dan barisnya kembali menjadi milik pribadi pemiliknya.
     */
    public function authorizeShareChange(TenantMembership $membership, Dashboard|SavedQuery $item, bool $shared): void
    {
        if ($shared === $item->shared) {
            return;
        }

        abort_unless($this->mayShare($membership), 403, 'Anda belum boleh membagikan dasbor dan analisis ke semua pengguna.');
        abort_if($shared && $item->user_id !== (int) $membership->user_id, 403, 'Hanya pemiliknya yang dapat membagikan dasbor atau analisis pribadi.');
    }

    /**
     * Baris yang boleh dilihat keanggotaan ini: milik sendiri dan yang bersama, di tenant-nya saja.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visible(Builder $query, TenantMembership $membership): Builder
    {
        return $query
            ->where($query->qualifyColumn('tenant_id'), $membership->tenant_id)
            ->where(fn (Builder $visible) => $visible
                ->where($query->qualifyColumn('user_id'), $membership->user_id)
                ->orWhere($query->qualifyColumn('shared'), true));
    }
}
