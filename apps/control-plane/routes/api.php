<?php

use App\Http\Controllers\Internal\FiscalCalendarDirectoryController;
use App\Http\Controllers\Internal\HrPositionAssignmentController;
use App\Http\Controllers\Internal\MemberDirectoryController;
use App\Http\Controllers\Internal\OrganizationDirectoryController;
use App\Http\Controllers\Internal\UnitOfMeasureDirectoryController;
use App\Http\Controllers\NumberSequence\InternalNumberSequenceController;
use App\Http\Controllers\Workflow\InternalWorkflowInstanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1')->middleware(['throttle:internal-app', 'internal-app'])->group(function (): void {
    Route::get('members', [MemberDirectoryController::class, 'index']);
    Route::get('members/{membership}', [MemberDirectoryController::class, 'show']);
    Route::get('operating-units', [OrganizationDirectoryController::class, 'operatingUnits']);
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
