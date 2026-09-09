<?php

use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Support\Facades\Artisan;
use Modules\Apperp\ManagementAset\Services\ProvisionIndonesiaStarterData;

/*
 * Perintah ini berjalan di luar permintaan HTTP, jadi tidak ada middleware konteks module yang
 * menetapkan tenant aktif. Model module menyaring lewat tenant aktif dan penyaringan itu gagal
 * menutup: tanpa `jalankanUntuk`, pembacaan pertama melempar "Query module dijalankan tanpa
 * tenant aktif" dan seluruh perintah berhenti sebelum menyemai apa pun.
 */
Artisan::command('management-aset:seed-maintenance {tenant? : ULID tenant tujuan} {--tenant= : ULID tenant tujuan (alternatif)} {--template-key=id:maintenance:starter:v1 : Versi template seed}', function (ProvisionIndonesiaStarterData $provisioner, PelaksanaUntukTenant $pelaksana): void {
    $tenantId = (string) ($this->option('tenant') ?: $this->argument('tenant'));
    if ($tenantId === '') {
        $this->error('Tenant wajib diisi melalui --tenant=<tenant_id>.');

        return;
    }
    $templateKey = (string) $this->option('template-key');
    $result = $pelaksana->jalankanUntuk(
        $tenantId,
        static fn (): array => $provisioner->maintenanceForTenant($tenantId, $templateKey),
    );
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));
})->purpose('Pasang seed setup maintenance Indonesia pada satu tenant secara manual.');
