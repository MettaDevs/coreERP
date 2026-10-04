<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use App\Platform\Environment\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Id dasbor, widget, dan query tersimpan di URL hanya dicari di tenant keanggotaan yang sedang aktif.
 *
 * Model Core tidak memakai `TenantScope` (scope itu milik konteks module), jadi tanpa ini route model binding
 * menemukan baris tenant mana pun dan setiap controller harus ingat membandingkan `tenant_id`. Dengan ini id
 * milik tenant lain menjadi 404 sebelum controller berjalan — keberadaannya tidak bocor lewat 403 — dan tanpa
 * keanggotaan aktif tidak ada yang ditemukan. Baris terarsip tidak ditemukan karena `SoftDeletes`.
 *
 * Aturan berbagi di dalam tenant (dasbor pribadi orang lain) diperiksa sesudahnya oleh `DashboardAccess`.
 *
 * @mixin Model
 */
trait BindsWithinActiveTenant
{
    /**
     * @param  mixed  $value
     * @param  string|null  $field
     * @return static|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $tenantId = app(CurrentWorkspace::class)->membership(request())?->tenant_id;
        if ($tenantId === null) {
            return null;
        }

        /** @var static|null */
        return $this->resolveRouteBindingQuery($this, $value, $field)
            ->where($this->qualifyColumn('tenant_id'), $tenantId)
            ->first();
    }
}
