<?php

namespace App\Actions\NumberSequence;

use App\Models\ModuleInstallation;
use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use Illuminate\Support\Facades\DB;

class EnsureNumberSequenceDrafts
{
    /**
     * Tenant yang berhak atas app ini **dan** sudah memasangnya sebagai module.
     *
     * Sampai 10 September 2026 penentunya adalah baris `app_placements` yang berstatus siap,
     * dan itu penentu yang salah bagi module: module tidak pernah punya penempatan container,
     * jadi jalur ini diam-diam tidak menemukan tenant mana pun dan tidak satu pun urutan nomor
     * dibuat. Akibatnya tidak terlihat sebagai pendaftaran katalog yang gagal, melainkan
     * sebagai dokumen pertama yang gagal disimpan di tangan pengguna.
     */
    public function forReadyApp(string $appId): void
    {
        $tenantIds = DB::table('core_module_installations as installations')
            ->join('tenant_app_entitlements as entitlements', function ($join) use ($appId): void {
                $join->on('entitlements.tenant_id', '=', 'installations.tenant_id')
                    ->where('entitlements.app_id', '=', $appId);
            })
            ->where('installations.module_id', $appId)
            ->where('installations.status', ModuleInstallation::STATUS_INSTALLED)
            ->where('entitlements.status', 'active')
            ->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->pluck('installations.tenant_id');

        foreach ($tenantIds->unique() as $tenantId) {
            $this->forTenantAndApp((string) $tenantId, $appId);
        }
    }

    /**
     * The single-tenant counterpart of forReadyApp, applying exactly the same readiness rules.
     *
     * forReadyApp walks every entitled tenant, which is correct when an app becomes ready but ruinous on a page load:
     * at a thousand tenants it costs thousands of round-trips per request, on behalf of tenants that are not even
     * looking. Anything request-scoped must use this instead.
     */
    public function forReadyTenant(string $tenantId): void
    {
        $appIds = DB::table('core_module_installations as installations')
            ->join('tenant_app_entitlements as entitlements', function ($join): void {
                $join->on('entitlements.tenant_id', '=', 'installations.tenant_id')
                    ->on('entitlements.app_id', '=', 'installations.module_id');
            })
            ->where('installations.tenant_id', $tenantId)
            ->where('installations.status', ModuleInstallation::STATUS_INSTALLED)
            ->where('entitlements.status', 'active')
            ->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->pluck('entitlements.app_id');

        foreach ($appIds->unique() as $appId) {
            $this->forTenantAndApp($tenantId, (string) $appId);
        }
    }

    public function forTenantAndApp(string $tenantId, string $appId): void
    {
        $profile = DB::table('number_sequence_profiles')->where('code', 'non-continuous-default')->first();
        if (! $profile) {
            return;
        }

        NumberSequenceReference::query()->where('app_id', $appId)->each(function (NumberSequenceReference $reference) use ($tenantId, $profile): void {
            TenantNumberSequence::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'reference_id' => $reference->id],
                [
                    'profile_code' => $profile->code,
                    'scope_type' => $this->defaultScope($reference),
                    'status' => 'active',
                    'is_continuous' => (bool) $profile->is_continuous,
                    'allow_manual' => (bool) $profile->allow_manual,
                    'preallocation_enabled' => (bool) $profile->preallocation_enabled,
                    'preallocation_quantity' => (int) $profile->preallocation_quantity,
                    'minimum_number' => 0,
                    'maximum_number' => 19999,
                    'segments' => array_values(array_filter([
                        $reference->default_prefix ? ['type' => 'constant', 'value' => $reference->default_prefix] : null,
                        ['type' => 'number', 'length' => 5],
                    ])),
                ],
            );
        });
    }

    private function defaultScope(NumberSequenceReference $reference): string
    {
        // Tenant is the least specific scope and remains the default whenever
        // the manifest permits it. Transaction references that only allow a
        // legal entity must materialize that narrower scope from the start.
        foreach (['tenant', 'legal_entity', 'operating_unit'] as $scope) {
            if (in_array($scope, $reference->allowed_scopes, true)) {
                return $scope;
            }
        }

        throw new \LogicException('Reference number sequence tidak memiliki scope yang valid: '.$reference->code);
    }
}
