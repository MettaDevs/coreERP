<?php

use App\Http\Controllers\Access\AccessController;
use App\Http\Controllers\Access\InvitationCodeController;
use App\Http\Controllers\Access\MembershipController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Onboarding\BusinessRegistrationController;
use App\Http\Controllers\Onboarding\InvitationRedemptionController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\WorkspaceContextController;
use App\Http\Controllers\Provider\IdentityMonitorController;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('ui-playground', 'ui-playground')->name('ui-playground');
Route::inertia('lottie', 'lottie-gallery')->name('lottie-gallery');

Route::get('api/v1/control/modules', fn () => response()->json([
    'data' => config('coreerp.module_catalog'),
]))->name('api.control.modules.index');

Route::middleware('guest')->group(function () {
    Route::get('join', fn () => Inertia::render('auth/join', [
        'passwordRules' => Password::defaults()->toPasswordRulesString(),
    ]))->name('join');
    Route::post('join', [InvitationRedemptionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('join.store');
    Route::post('api/v1/business-registrations', [BusinessRegistrationController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('api.business-registrations.store');
    Route::post('api/v1/invitation-redemptions', [InvitationRedemptionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('api.invitation-redemptions.store');
});

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('settings/access', [AccessController::class, 'index'])->name('access.index');
    Route::get('settings/organization', [OrganizationController::class, 'index'])->name('organization.index');
    Route::post('settings/organization/organizations', [OrganizationController::class, 'store'])
        ->name('organization.organizations.store');
    Route::post('settings/organization/hierarchies', [OrganizationController::class, 'storeHierarchy'])
        ->name('organization.hierarchies.store');
    Route::post('settings/organization/hierarchy-versions/{version}/placements', [OrganizationController::class, 'place'])
        ->name('organization.hierarchy-versions.placements.store');
    Route::post('settings/organization/hierarchy-versions/{version}/publish', [OrganizationController::class, 'publish'])
        ->name('organization.hierarchy-versions.publish');

    Route::post('settings/access/roles', [RoleController::class, 'store'])->name('access.roles.store');
    Route::put('settings/access/roles/{role}', [RoleController::class, 'update'])->name('access.roles.update');
    Route::delete('settings/access/roles/{role}', [RoleController::class, 'destroy'])->name('access.roles.destroy');
    Route::patch('settings/access/memberships/{membership}', [MembershipController::class, 'update'])
        ->name('access.memberships.update');
    Route::post('settings/access/invitations', [InvitationCodeController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('access.invitations.store');
    Route::delete('settings/access/invitations/{invitationCode}', [InvitationCodeController::class, 'destroy'])
        ->name('access.invitations.destroy');

    Route::get('control/modules', fn () => Inertia::render('control/modules', [
        'modules' => config('coreerp.module_catalog'),
    ]))->name('control.modules');
    Route::get('control/identities', [IdentityMonitorController::class, 'index'])->name('control.identities');

    Route::prefix('api/v1')->name('api.')->group(function () {
        Route::put('workspace-context', [WorkspaceContextController::class, 'update'])
            ->name('workspace-context.update');
        Route::apiResource('roles', RoleController::class);
        Route::get('memberships/{membership}', [MembershipController::class, 'show'])->name('memberships.show');
        Route::patch('memberships/{membership}', [MembershipController::class, 'update'])->name('memberships.update');
        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('invitation-codes', [InvitationCodeController::class, 'index'])->name('invitation-codes.index');
        Route::post('invitation-codes', [InvitationCodeController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('invitation-codes.store');
        Route::delete('invitation-codes/{invitationCode}', [InvitationCodeController::class, 'destroy'])
            ->name('invitation-codes.destroy');
        Route::get('control/identities', [IdentityMonitorController::class, 'apiIndex'])
            ->name('control.identities.index');
    });
});

require __DIR__.'/settings.php';
