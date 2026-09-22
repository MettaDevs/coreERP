<?php

use App\Http\Controllers\Internal\EnvironmentProvisioningController;
use App\Http\Controllers\Internal\FiscalCalendarDirectoryController;
use App\Http\Controllers\Internal\FleetController;
use App\Http\Controllers\Internal\HrPositionAssignmentController;
use App\Http\Controllers\Internal\MemberDirectoryController;
use App\Http\Controllers\Internal\OrganizationDirectoryController;
use App\Http\Controllers\Internal\TenantEntitlementController;
use App\Http\Controllers\Internal\TenantProvisioningController;
use App\Http\Controllers\Internal\UnitOfMeasureDirectoryController;
use App\Http\Controllers\NumberSequence\InternalNumberSequenceController;
use App\Http\Controllers\Workflow\InternalWorkflowInstanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1')->middleware(['throttle:internal-app', 'internal-app'])->group(function (): void {
    Route::get('members', [MemberDirectoryController::class, 'index']);
    Route::get('members/{membership}', [MemberDirectoryController::class, 'show']);
    Route::get('fiscal-periods', [FiscalCalendarDirectoryController::class, 'resolve']);
    Route::get('units-of-measure', [UnitOfMeasureDirectoryController::class, 'index']);
    Route::post('units-of-measure/resolve', [UnitOfMeasureDirectoryController::class, 'resolve']);
    Route::post('units-of-measure/convert', [UnitOfMeasureDirectoryController::class, 'convert']);
    Route::post('human-resources/position-assignments', [HrPositionAssignmentController::class, 'store']);
    Route::post('number-sequences/{reference}/issue', [InternalNumberSequenceController::class, 'issue']);
    Route::post('number-sequences/{reference}/reserve', [InternalNumberSequenceController::class, 'reserve']);
    Route::post('number-sequence-reservations/{reservation}/confirm', [InternalNumberSequenceController::class, 'confirm']);
    Route::post('number-sequence-reservations/{reservation}/cancel', [InternalNumberSequenceController::class, 'cancel']);
    Route::post('workflow-instances', [InternalWorkflowInstanceController::class, 'store']);
});

/*
 * Dibaca module dan sistem di luar CoreERP sekaligus.
 *
 * Module human-resources memakai kredensial app seperti rute lain di atas. Pembaca feed posting
 * finance memakai token klien integrasi dengan cakupan yang disebut di parameter middleware, dan
 * menyinkronkan tabel penerjemahnya dari rute yang sama. Lihat AuthenticateInternalCaller.
 */
Route::prefix('internal/v1')->middleware(['throttle:internal-caller', 'internal-caller:operating-units.read'])->group(function (): void {
    Route::get('operating-units', [OrganizationDirectoryController::class, 'operatingUnits']);
});

/*
 * Perintah yang datang dari pusat admin, bukan dari app module.
 *
 * Grupnya terpisah karena penjaganya berbeda, dan bedanya bukan selera: `internal-app` menuntut
 * app yang terpasang pada sebuah tenant, sedangkan yang di sini justru sedang membuat tenantnya.
 * Alasan lengkapnya di App\Http\Middleware\ControlPlaneOnly.
 *
 * Throttle-nya juga terpisah. `internal-app` memberi kunci per app dan per tenant lewat header
 * kredensial yang tidak dikirim pemanggil ini — seluruh perintah pusat admin akan berbagi satu
 * kunci "unknown", jadi angkanya tidak berarti apa-apa. Yang di sini per alamat, dan sengaja kecil:
 * melahirkan tenant bukan sesuatu yang dilakukan puluhan kali per menit, dan ia menjalankan
 * migration beserta pemasangan module di belakangnya.
 */
Route::prefix('internal/v1')->middleware(['throttle:30,1', 'control-plane'])->group(function (): void {
    Route::post('tenants', [TenantProvisioningController::class, 'store']);
    // Dibaca admin.erp saat menerbitkan lisensi situs; lihat TenantEntitlementController.
    Route::get('tenants/{tenant}/entitlements', [TenantEntitlementController::class, 'show']);
    Route::post('environments/{environment}/provision', [EnvironmentProvisioningController::class, 'store']);
    Route::get('fleet', [FleetController::class, 'index']);
    Route::post('environments/upgrade', [FleetController::class, 'upgrade']);
    Route::post('environments/{environment}/upgrade', [FleetController::class, 'upgrade']);
});
