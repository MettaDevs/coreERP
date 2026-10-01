<?php

declare(strict_types=1);

namespace App\Platform\Access\Support;

use App\Platform\Access\Models\SecurityDuty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Produk yang katalog keamanannya boleh dipakai sebuah tenant: app yang entitlement-nya aktif, ditambah Core
 * sendiri. Core tidak pernah dibeli — layarnya ada di setiap tenant — jadi ia tidak punya baris entitlement,
 * tetapi duty-nya tetap harus dapat disusun ke dalam role (SEC-22).
 *
 * Satu sumber untuk layar role, konfigurasi keamanan, dan penyelaras role Owner, supaya "duty yang sah untuk
 * tenant ini" tidak dijawab berbeda di tiga tempat.
 */
final class TenantProducts
{
    /** @return list<string> */
    public static function appIds(string $tenantId): array
    {
        $entitled = DB::table('tenant_app_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('app_id')
            ->map(strval(...))
            ->all();

        return array_values(array_unique([CoreSecurityCatalog::APP_ID, ...$entitled]));
    }

    /**
     * Duty yang boleh disusun ke dalam role tenant ini: duty produk yang boleh dipakainya, dan duty buatan tenant
     * sendiri yang aktif.
     *
     * @return Builder<SecurityDuty>
     */
    public static function duties(string $tenantId): Builder
    {
        $appIds = self::appIds($tenantId);

        return SecurityDuty::query()->where(function ($query) use ($tenantId, $appIds): void {
            $query->where(fn ($query) => $query->whereNull('tenant_id')->whereIn('app_id', $appIds))
                ->orWhere(fn ($query) => $query->where('tenant_id', $tenantId)->where('source', 'custom')->where('status', 'active'));
        });
    }
}
