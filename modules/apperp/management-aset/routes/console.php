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
    // `option()` dan `argument()` menjanjikan array|bool|float|int|string|null karena harus
    // melayani setiap bentuk definisi perintah. Ketiga nilai di bawah dideklarasikan sebagai
    // nilai tunggal, jadi bentuk lain tidak pernah muncul dan diperlakukan sebagai tidak diisi.
    $opsiTenant = $this->option('tenant');
    $argumenTenant = $this->argument('tenant');
    $tenantId = (is_string($opsiTenant) ? $opsiTenant : '') ?: (is_string($argumenTenant) ? $argumenTenant : '');
    if ($tenantId === '') {
        $this->error('Tenant wajib diisi melalui --tenant=<tenant_id>.');

        return;
    }
    $opsiTemplate = $this->option('template-key');
    $templateKey = is_string($opsiTemplate) ? $opsiTemplate : '';
    $result = $pelaksana->jalankanUntuk(
        $tenantId,
        static fn (): array => $provisioner->maintenanceForTenant($tenantId, $templateKey),
    );
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));
})->purpose('Pasang seed setup maintenance Indonesia pada satu tenant secara manual.');
