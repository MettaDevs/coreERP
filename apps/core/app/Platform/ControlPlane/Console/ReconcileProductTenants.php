<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Console;

use App\Platform\ControlPlane\Models\ManagedProductTenant;
use App\Platform\ControlPlane\Products\ProductTenantProvisioner;
use Illuminate\Console\Command;

final class ReconcileProductTenants extends Command
{
    protected $signature = 'product-tenants:reconcile';

    protected $description = 'Lanjutkan penyiapan tenant produk yang belum selesai';

    public function handle(ProductTenantProvisioner $provisioner): int
    {
        ManagedProductTenant::query()->whereIn('status', ['queued', 'provisioning'])->eachById(
            fn (ManagedProductTenant $tenant) => $provisioner->reconcile($tenant->id),
            50,
        );
        ManagedProductTenant::query()->whereNotNull('pending_sso_revocation')->eachById(
            fn (ManagedProductTenant $tenant) => $provisioner->reconcileSsoRevocation($tenant->id),
            50,
        );

        return self::SUCCESS;
    }
}
