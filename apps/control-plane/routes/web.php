<?php

use App\Http\Controllers\Access\AccessController;
use App\Http\Controllers\Access\InvitationCodeController;
use App\Http\Controllers\Access\MembershipController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\SecurityConfigurationController;
use App\Http\Controllers\AppLaunchManifestController;
use App\Http\Controllers\FiscalCalendar\FiscalCalendarController;
use App\Http\Controllers\NumberSequence\NumberSequenceController;
use App\Http\Controllers\Onboarding\BusinessRegistrationController;
use App\Http\Controllers\Onboarding\InvitationRedemptionController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\WorkspaceContextController;
use App\Http\Controllers\Provider\AppCatalogController;
use App\Http\Controllers\Provider\AppReleaseController;
use App\Http\Controllers\Provider\AppServiceCredentialController;
use App\Http\Controllers\Provider\IdentityMonitorController;
use App\Http\Controllers\ReferenceData\UnitOfMeasureController;
use App\Http\Controllers\Workflow\WorkflowConfigurationController;
use App\Http\Controllers\Workflow\WorkflowInboxController;
use App\Models\CoreApp;
use App\Support\AppContextToken;
use App\Support\CurrentWorkspace;
use App\Support\LaunchableAppCatalog;
use App\Support\DataPolicyAccessResolver;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Http\Request;
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

    // Contract dimiliki repository app penerbit, bukan repository platform ini.
    // Portal hanya mengarahkan ke contract yang didaftarkan app pada katalog.
    Route::get('docs/openapi/{document}', function (string $document) {
        $app = CoreApp::query()->whereKey($document)->where('status', 'available')->first();
        abort_if($app === null || blank($app->contract_url), 404);

        return redirect()->away($app->contract_url);
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
        ->middleware('throttle:'.config('coreerp.registration_rate_limit', 5).',1')
        ->name('api.business-registrations.store');
    Route::post('api/v1/invitation-redemptions', [InvitationRedemptionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('api.invitation-redemptions.store');
});

Route::middleware(['auth'])->group(function () {
    Route::get('apps/{app}', function (CoreApp $app, Request $request, CurrentWorkspace $workspace, LaunchableAppCatalog $catalog, AppContextToken $tokens) {
        $membership = $workspace->membership($request);
        abort_unless($membership && collect($catalog->for($membership))->contains('id', $app->id), 403);
        $runtimeEntry = $catalog->runtimeFor($membership, $app->id);
        abort_unless($runtimeEntry, 404);
        $navigation = $catalog->navigationFor($membership, $app);
        $items = collect($navigation)->flatMap(fn (array $rail): array => $rail['items']);
        $activeItem = $items->firstWhere('id', $request->string('view')->toString()) ?? $items->first();
        $contentEntry = $runtimeEntry;

        if ($activeItem) {
            $contentEntry = rtrim($contentEntry, '#/').'/#/'.rawurlencode($activeItem['id']);
        }

        return Inertia::render('apps/host', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'contentEntry' => $contentEntry,
                'navigation' => [
                    'rails' => $navigation,
                    'activeItemId' => $activeItem['id'] ?? null,
                ],
                'contextToken' => $tokens->issue(
                    $membership,
                    $app->id,
                    $catalog->permissionsFor($membership, $app->id),
                    $workspace->legalEntity($request, $membership)?->id,
                    $workspace->operatingUnit($request, $membership)?->id,
                    app(DataPolicyAccessResolver::class)->resolve($membership),
                ),
            ],
        ]);
    })->name('apps.host');

    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('settings/access', [AccessController::class, 'index'])->name('access.index');
    Route::get('settings/security-configuration', [SecurityConfigurationController::class, 'index'])->name('security-configuration.index');
    Route::post('settings/security-configuration/privileges', [SecurityConfigurationController::class, 'storePrivilege'])->name('security-configuration.privileges.store');
    Route::put('settings/security-configuration/privileges/{privilege}', [SecurityConfigurationController::class, 'updatePrivilege'])->name('security-configuration.privileges.update');
    Route::post('settings/security-configuration/privileges/{privilege}/publish', [SecurityConfigurationController::class, 'publishPrivilege'])->name('security-configuration.privileges.publish');
    Route::post('settings/security-configuration/duties', [SecurityConfigurationController::class, 'storeDuty'])->name('security-configuration.duties.store');
    Route::put('settings/security-configuration/duties/{duty}', [SecurityConfigurationController::class, 'updateDuty'])->name('security-configuration.duties.update');
    Route::post('settings/security-configuration/duties/{duty}/publish', [SecurityConfigurationController::class, 'publishDuty'])->name('security-configuration.duties.publish');
    Route::get('settings/organization', [OrganizationController::class, 'index'])->name('organization.index');
    Route::get('settings/number-sequences', [NumberSequenceController::class, 'index'])->name('number-sequences.index');
    Route::patch('settings/number-sequences/{sequence}', [NumberSequenceController::class, 'update'])->name('number-sequences.update');
    Route::get('settings/fiscal-calendars', [FiscalCalendarController::class, 'index'])->name('fiscal-calendars.index');
    Route::get('settings/units-of-measure', [UnitOfMeasureController::class, 'index'])->name('units-of-measure.index');
    Route::get('settings/workflows', [WorkflowConfigurationController::class, 'index'])->name('workflows.index');
    Route::post('settings/workflows', [WorkflowConfigurationController::class, 'store'])->name('workflows.store');
    Route::get('settings/workflows/{workflow}/edit', [WorkflowConfigurationController::class, 'edit'])->name('workflows.edit');
    Route::get('settings/workflows/{workflow}/graph', [WorkflowConfigurationController::class, 'graph'])->name('workflows.graph');
    Route::post('settings/workflows/{workflow}/draft', [WorkflowConfigurationController::class, 'createDraft'])->name('workflows.draft');
    Route::put('settings/workflows/{workflow}/graph', [WorkflowConfigurationController::class, 'updateGraph'])->name('workflows.graph.update');
    Route::post('settings/workflows/{workflow}/publish', [WorkflowConfigurationController::class, 'publish'])->name('workflows.publish');
    Route::post('settings/workflows/{workflow}/activate', [WorkflowConfigurationController::class, 'activate'])->name('workflows.activate');
    Route::post('settings/workflows/{workflow}/deactivate', [WorkflowConfigurationController::class, 'deactivate'])->name('workflows.deactivate');
    Route::get('workflow-inbox', [WorkflowInboxController::class, 'index'])->name('workflow-inbox.index');
    Route::post('workflow-inbox/{workItem}/decision', [WorkflowInboxController::class, 'decide'])->name('workflow-inbox.decide');
    Route::post('settings/units-of-measure/classes', [UnitOfMeasureController::class, 'storeClass'])->name('units-of-measure.classes.store');
    Route::post('settings/units-of-measure/systems', [UnitOfMeasureController::class, 'storeSystem'])->name('units-of-measure.systems.store');
    Route::post('settings/units-of-measure', [UnitOfMeasureController::class, 'store'])->name('units-of-measure.store');
    Route::patch('settings/units-of-measure/{unit}', [UnitOfMeasureController::class, 'update'])->name('units-of-measure.update');
    Route::post('settings/units-of-measure/conversions', [UnitOfMeasureController::class, 'storeConversion'])->name('units-of-measure.conversions.store');
    Route::post('settings/fiscal-calendars', [FiscalCalendarController::class, 'store'])->name('fiscal-calendars.store');
    Route::post('settings/fiscal-calendars/{calendar}/years', [FiscalCalendarController::class, 'storeYear'])->name('fiscal-calendars.years.store');
    Route::post('settings/fiscal-calendars/{calendar}/assign', [FiscalCalendarController::class, 'assign'])->name('fiscal-calendars.assign');
    Route::post('settings/organization/organizations', [OrganizationController::class, 'store'])
        ->name('organization.organizations.store');
    Route::patch('settings/organization/organizations/{organization}', [OrganizationController::class, 'update'])
        ->name('organization.organizations.update');
    Route::post('settings/organization/hierarchies', [OrganizationController::class, 'storeHierarchy'])
        ->name('organization.hierarchies.store');
    Route::post('settings/organization/hierarchy-versions/{version}/placements', [OrganizationController::class, 'place'])
        ->name('organization.hierarchy-versions.placements.store');
    Route::delete('settings/organization/hierarchy-versions/{version}/placements/{node}', [OrganizationController::class, 'unplace'])
        ->name('organization.hierarchy-versions.placements.destroy');
    Route::post('settings/organization/hierarchy-versions/{version}/drafts', [OrganizationController::class, 'createDraft'])
        ->name('organization.hierarchy-versions.drafts.store');
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
        Route::get('number-sequences', [NumberSequenceController::class, 'index'])->name('number-sequences.index');
        Route::patch('number-sequences/{sequence}', [NumberSequenceController::class, 'update'])->name('number-sequences.update');
        Route::post('number-sequences/{sequence}/advance', [NumberSequenceController::class, 'advance'])->name('number-sequences.advance');
        Route::get('fiscal-calendars', [FiscalCalendarController::class, 'index'])->name('fiscal-calendars.index');
        Route::get('units-of-measure', [UnitOfMeasureController::class, 'index'])->name('units-of-measure.index');
        Route::post('units-of-measure/classes', [UnitOfMeasureController::class, 'storeClass'])->name('units-of-measure.classes.store');
        Route::post('units-of-measure/systems', [UnitOfMeasureController::class, 'storeSystem'])->name('units-of-measure.systems.store');
        Route::post('units-of-measure', [UnitOfMeasureController::class, 'store'])->name('units-of-measure.store');
        Route::patch('units-of-measure/{unit}', [UnitOfMeasureController::class, 'update'])->name('units-of-measure.update');
        Route::post('units-of-measure/conversions', [UnitOfMeasureController::class, 'storeConversion'])->name('units-of-measure.conversions.store');
        Route::post('fiscal-calendars', [FiscalCalendarController::class, 'store'])->name('fiscal-calendars.store');
        Route::post('fiscal-calendars/{calendar}/years', [FiscalCalendarController::class, 'storeYear'])->name('fiscal-calendars.years.store');
        Route::post('fiscal-calendars/{calendar}/assign', [FiscalCalendarController::class, 'assign'])->name('fiscal-calendars.assign');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::patch('organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
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
        Route::post('provider/apps/{app}/releases', [AppReleaseController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('provider.app-releases.store');
        Route::post('provider/apps/{app}/service-credentials', [AppServiceCredentialController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('provider.app-service-credentials.store');
        Route::get('workflows', [WorkflowConfigurationController::class, 'index'])->name('workflows.index');
        Route::post('workflows', [WorkflowConfigurationController::class, 'store'])->name('workflows.store');
        Route::get('workflows/{workflow}/graph', [WorkflowConfigurationController::class, 'graph'])->name('workflows.graph');
        Route::put('workflows/{workflow}/graph', [WorkflowConfigurationController::class, 'updateGraph'])->name('workflows.graph.update');
        Route::post('workflows/{workflow}/draft', [WorkflowConfigurationController::class, 'createDraft'])->name('workflows.draft');
        Route::post('workflows/{workflow}/publish', [WorkflowConfigurationController::class, 'publish'])->name('workflows.publish');
        Route::post('workflows/{workflow}/activate', [WorkflowConfigurationController::class, 'activate'])->name('workflows.activate');
        Route::post('workflows/{workflow}/deactivate', [WorkflowConfigurationController::class, 'deactivate'])->name('workflows.deactivate');
    });
});

require __DIR__.'/settings.php';
