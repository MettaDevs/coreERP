<?php

use App\Http\Controllers\Access\AccessController;
use App\Http\Controllers\AppLaunchManifestController;
use App\Http\Controllers\Access\InvitationCodeController;
use App\Http\Controllers\Access\MembershipController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Onboarding\BusinessRegistrationController;
use App\Http\Controllers\Onboarding\InvitationRedemptionController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\WorkspaceContextController;
use App\Http\Controllers\Provider\IdentityMonitorController;
use App\Http\Controllers\Provider\AppCatalogController;
use App\Support\CurrentWorkspace;
use App\Support\LaunchableAppCatalog;
use App\Models\CoreApp;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('ui-playground', 'ui-playground')->name('ui-playground');
Route::inertia('lottie', 'lottie-gallery')->name('lottie-gallery');

Route::middleware(RestrictedDocsAccess::class)->group(function () {
    Route::get('docs', function () {
        $specifications = CoreApp::query()->where('status', 'available')->orderBy('name')->get()
            ->map(fn (CoreApp $app): array => [
                'id' => $app->id,
                'name' => $app->name,
                'url' => route('docs.openapi', $app->id),
            ])
            ->prepend([
                'id' => 'control-plane',
                'name' => config('app.name').' Control Plane',
                'url' => route('scramble.docs.document'),
            ])
            ->values();
        $selected = $specifications->firstWhere('id', request()->query('spec')) ?? $specifications->first();

        return view('api-portal', compact('selected', 'specifications'));
    })->name('docs.portal');

    Route::get('docs/openapi/{document}', function (string $document) {
        abort_unless(CoreApp::query()->whereKey($document)->where('status', 'available')->exists(), 404);

        $path = base_path("contracts/apps/{$document}.yaml");
        abort_unless(File::isFile($path), 404);

        return response()->file($path, ['Content-Type' => 'application/yaml']);
    })->where('document', '[A-Za-z0-9-]+')->name('docs.openapi');
});

Route::get('api/v1/control/apps', fn () => response()->json([
    'data' => CoreApp::query()->where('status', 'available')->orderBy('name')->get([
        'id', 'name', 'description', 'version', 'database_name', 'ui_entry',
    ]),
]))->name('api.control.apps.index');

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
    Route::get('apps/{app}', function (CoreApp $app, \Illuminate\Http\Request $request, CurrentWorkspace $workspace, LaunchableAppCatalog $catalog) {
        $membership = $workspace->membership($request);
        abort_unless($membership && collect($catalog->for($membership))->contains('id', $app->id), 403);

        return Inertia::render('apps/host', [
            'app' => ['id' => $app->id, 'name' => $app->name, 'contentEntry' => $app->ui_entry],
        ]);
    })->name('apps.host');

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

    Route::get('control/apps', fn () => Inertia::render('control/apps', [
        'apps' => CoreApp::query()->orderBy('name')->get()->map(fn (CoreApp $app): array => [
            'id' => $app->id,
            'name' => $app->name,
            'version' => $app->version,
            'status' => $app->status,
            'database_name' => $app->database_name,
            'description' => $app->description ?? '',
        ])->values(),
    ]))->name('control.apps');
    Route::get('control/identities', [IdentityMonitorController::class, 'index'])->name('control.identities');

    Route::prefix('api/v1')->name('api.')->group(function () {
        Route::get('launch-manifest', AppLaunchManifestController::class)->name('launch-manifest.show');
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
        Route::get('provider/apps', [AppCatalogController::class, 'index'])->name('provider.apps.index');
        Route::post('provider/apps', [AppCatalogController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('provider.apps.store');
    });
});

require __DIR__.'/settings.php';
