<?php

use Illuminate\Support\Facades\Artisan;
use Modules\Apperp\ManagementAset\Services\ProvisionIndonesiaStarterData;

Artisan::command('management-aset:seed-maintenance {tenant? : ULID tenant tujuan} {--tenant= : ULID tenant tujuan (alternatif)} {--template-key=id:maintenance:starter:v1 : Versi template seed}', function (ProvisionIndonesiaStarterData $provisioner): void {
    $tenantId = (string) ($this->option('tenant') ?: $this->argument('tenant'));
    if ($tenantId === '') {
        $this->error('Tenant wajib diisi melalui --tenant=<tenant_id>.');

        return;
    }
    $result = $provisioner->maintenanceForTenant($tenantId, (string) $this->option('template-key'));
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));
})->purpose('Pasang seed setup maintenance Indonesia pada satu tenant secara manual.');
