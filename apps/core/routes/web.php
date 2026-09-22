<?php

use App\Http\Controllers\Access\AccessController;
use App\Http\Controllers\Access\InvitationCodeController;
use App\Http\Controllers\Access\MembershipController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\SecurityConfigurationController;
use App\Http\Controllers\AppLaunchManifestController;
use App\Http\Controllers\Auth\SsoBackchannelLogoutController;
use App\Http\Controllers\Auth\SsoLoginController;
use App\Http\Controllers\Calendar\WorkingTimeTemplateController;
use App\Http\Controllers\Docs\DocsPortalController;
use App\Http\Controllers\Finance\CurrencyPrecisionController;
use App\Http\Controllers\Finance\FinancePostingSettingController;
use App\Http\Controllers\Finance\IntegrationClientController;
use App\Http\Controllers\Finance\ReferenceAccountController;
use App\Http\Controllers\Finance\VendorController;
use App\Http\Controllers\FiscalCalendar\FiscalCalendarController;
use App\Http\Controllers\GlobalAddressBook\OrganizationContactController;
use App\Http\Controllers\GlobalAddressBook\OrganizationLocationController;
use App\Http\Controllers\NumberSequence\NumberSequenceController;
use App\Http\Controllers\Onboarding\BusinessRegistrationController;
use App\Http\Controllers\Onboarding\InvitationLandingController;
use App\Http\Controllers\Onboarding\InvitationRedemptionController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\PrintIdentityController;
use App\Http\Controllers\Organization\WorkspaceContextController;
use App\Http\Controllers\Provider\AppCatalogController;
use App\Http\Controllers\Provider\AppServiceCredentialController;
use App\Http\Controllers\Provider\IdentityMonitorController;
use App\Http\Controllers\ReferenceData\AddressHierarchy\AddressSetupController;
use App\Http\Controllers\ReferenceData\UnitOfMeasureController;
use App\Http\Controllers\Reporting\ReportController;
use App\Http\Controllers\Reporting\ReportExportController;
use App\Http\Controllers\Reporting\ReportLayoutController;
use App\Http\Controllers\Workflow\WorkflowConfigurationController;
use App\Http\Controllers\Workflow\WorkflowInboxController;
use App\Models\CoreApp;
use App\Support\CurrentWorkspace;
use App\Support\LaunchableAppCatalog;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('ui-playground', 'ui-playground')->name('ui-playground');

/*
 * Portal dokumentasi API. Kontrak integrasi untuk sistem di luar CoreERP terbit tanpa login;
 * referensi internal dijaga gate `viewApiDocs`. Alasannya di DocsPortalController.
 */
Route::get('docs', DocsPortalController::class)->name('docs.portal');
Route::get('docs/kontrak/{spesifikasi}.yaml', [DocsPortalController::class, 'kontrak'])
    ->where('spesifikasi', '[a-z-]+')
    ->name('docs.kontrak');

Route::middleware(RestrictedDocsAccess::class)->group(function () {
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
        'id', 'name', 'description', 'version', 'database_name', 'has_ui',
    ]),
]))->name('api.control.apps.index');

/*
 * Penukaran kode undangan sengaja BERADA DI LUAR grup `guest`.
 *
 * Selama ia dijaga `guest`, orang yang sudah punya akun tidak dapat mencapainya sama sekali — dan
 * "satu orang di banyak tenant" mustahil lewat jalur mana pun: ia harus keluar lebih dulu, lalu
 * menukar kode memakai email yang pasti ditolak karena sudah terdaftar.
 *
 * Yang memilih jalurnya adalah controller-nya, dari ada atau tidaknya pengguna pada permintaan.
 * Orang baru mengirim nama, email, dan kata sandi; orang yang sudah masuk hanya mengirim kodenya.
 */
Route::get('join', [InvitationRedemptionController::class, 'show'])->name('join');

/*
 * Pendaratan tautan undangan di domain dasar. Penyedia SSO menolak mengirim email yang tautannya
 * menuju host di luar alamat balik client — dan alamat balik itu ada di domain dasar, bukan di
 * alamat tenant. Lihat `InvitationLandingController`.
 */
Route::get('undangan', InvitationLandingController::class)
    ->middleware('throttle:30,1')
    ->name('undangan');
Route::post('join', [InvitationRedemptionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('join.store');
Route::post('api/v1/invitation-redemptions', [InvitationRedemptionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('api.invitation-redemptions.store');

/*
 * Masuk lewat penyedia identitas bersama. Bentuk tiga langkah dan dua alamatnya dijelaskan di
 * SsoLoginController. Keempatnya menjawab 404 selama penyedia tidak disetel.
 */
Route::get('sso/masuk', [SsoLoginController::class, 'start'])
    ->middleware(['guest', 'throttle:30,1'])
    ->name('sso.start');
// Bukan `guest`: upacara "hubungkan" kembali ke sini dengan akun yang sedang masuk.
Route::get('sso/serah', [SsoLoginController::class, 'handoff'])
    ->middleware('throttle:30,1')
    ->name('sso.handoff');
// Hanya dari layar keamanan, sesudah kata sandi dikonfirmasi ulang. Lihat SsoLoginController.
Route::middleware(['auth', RequirePassword::class, 'throttle:10,1'])->group(function () {
    Route::post('sso/hubungkan', [SsoLoginController::class, 'connect'])->name('sso.connect');
    Route::delete('sso/hubungkan', [SsoLoginController::class, 'disconnect'])->name('sso.disconnect');
});
/*
 * Menukarkan undangan terikat SSO. Tamu saja: undangan terikat dibuktikan lewat upacara, bukan lewat
 * sesi yang kebetulan sedang terbuka. Orang yang sudah masuk dan ingin tenant kedua memakai kode
 * anonim, atau keluar dulu.
 */
Route::post('sso/gabung', [SsoLoginController::class, 'join'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('sso.join');
Route::get('sso/callback', [SsoLoginController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('sso.callback');
Route::post('sso/backchannel-logout', SsoBackchannelLogoutController::class)
    ->middleware('throttle:60,1')
    ->name('sso.backchannel-logout');

Route::middleware('guest')->group(function () {
    Route::post('api/v1/business-registrations', [BusinessRegistrationController::class, 'store'])
        ->middleware('throttle:'.config('coreerp.registration_rate_limit', 5).',1')
        ->name('api.business-registrations.store');
});

Route::middleware(['auth'])->group(function () {
    /*
     * Tautan tunggal ke sebuah produk: `/apps/<id>`.
     *
     * Sebuah app tidak lagi punya halaman tuan rumah sendiri — tidak ada iframe, tidak ada
     * runtime kedua, dan tidak ada alamat konten yang perlu disusun. Yang tersisa adalah satu
     * pengalihan ke entri menu pertama yang boleh dilihat pengguna ini, karena halaman
     * sesungguhnya dirender module pada rutenya sendiri.
     *
     * Rutenya tetap ada meski hanya mengalihkan: ia satu-satunya tautan yang benar untuk
     * peluncur produk, yang tidak tahu—dan tidak perlu tahu—entri menu mana yang pertama boleh
     * dilihat oleh pengguna yang sedang masuk.
     */
    Route::get('apps/{app}', function (CoreApp $app, Request $request, CurrentWorkspace $workspace, LaunchableAppCatalog $catalog) {
        $membership = $workspace->membership($request);
        abort_unless($membership && collect($catalog->for($membership))->contains('id', $app->id), 403);

        $tujuan = collect($catalog->navigationFor($membership, $app))
            ->flatMap(fn (array $rail): array => $rail['items'])
            ->first();
        abort_if($tujuan === null, 404);

        return redirect($tujuan['href']);
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
    Route::post('settings/security-configuration/privileges/{privilege}/duplicate', [SecurityConfigurationController::class, 'duplicatePrivilege'])->name('security-configuration.privileges.duplicate');
    Route::post('settings/security-configuration/duties/{duty}/duplicate', [SecurityConfigurationController::class, 'duplicateDuty'])->name('security-configuration.duties.duplicate');
    Route::delete('settings/security-configuration/privileges/{privilege}', [SecurityConfigurationController::class, 'destroyPrivilege'])->name('security-configuration.privileges.destroy');
    Route::get('settings/organization', [OrganizationController::class, 'index'])->name('organization.index');
    Route::inertia('settings/global-address-book', 'settings/global-address-book/index')->name('global-address-book.index');
    Route::get('settings/number-sequences', [NumberSequenceController::class, 'index'])->name('number-sequences.index');
    Route::patch('settings/number-sequences/{sequence}', [NumberSequenceController::class, 'update'])->name('number-sequences.update');
    Route::get('settings/fiscal-calendars', [FiscalCalendarController::class, 'index'])->name('fiscal-calendars.index');
    Route::get('settings/working-time-templates', [WorkingTimeTemplateController::class, 'index'])->name('working-time-templates.index');
    Route::post('settings/working-time-templates', [WorkingTimeTemplateController::class, 'store'])->name('working-time-templates.store');
    Route::put('settings/working-time-templates/{template}', [WorkingTimeTemplateController::class, 'update'])->name('working-time-templates.update');
    Route::delete('settings/working-time-templates/{template}', [WorkingTimeTemplateController::class, 'destroy'])->name('working-time-templates.destroy');
    Route::put('settings/working-time-templates/{template}/lines', [WorkingTimeTemplateController::class, 'updateLines'])->name('working-time-templates.lines.update');
    Route::post('settings/working-time-templates/{template}/copy', [WorkingTimeTemplateController::class, 'copy'])->name('working-time-templates.copy');
    Route::get('settings/address-setup', [AddressSetupController::class, 'index'])->name('address-setup.index');
    Route::get('settings/address setup', fn (Request $r) => redirect('/settings/address-setup?'.http_build_query($r->query())));
    Route::get('settings/address_setup', fn (Request $r) => redirect('/settings/address-setup?'.http_build_query($r->query())));
    // Countries
    Route::post('settings/address-setup/countries', [AddressSetupController::class, 'storeCountry'])->name('address-setup.countries.store');
    Route::delete('settings/address-setup/countries/{code}', [AddressSetupController::class, 'destroyCountry'])->name('address-setup.countries.destroy');
    // Provinces
    Route::post('settings/address-setup/provinces', [AddressSetupController::class, 'storeProvince'])->name('address-setup.provinces.store');
    Route::delete('settings/address-setup/provinces/{province}', [AddressSetupController::class, 'destroyProvince'])->name('address-setup.provinces.destroy');
    // Regencies
    Route::post('settings/address-setup/regencies', [AddressSetupController::class, 'storeRegency'])->name('address-setup.regencies.store');
    Route::delete('settings/address-setup/regencies/{regency}', [AddressSetupController::class, 'destroyRegency'])->name('address-setup.regencies.destroy');
    // Districts
    Route::post('settings/address-setup/districts', [AddressSetupController::class, 'storeDistrict'])->name('address-setup.districts.store');
    Route::delete('settings/address-setup/districts/{district}', [AddressSetupController::class, 'destroyDistrict'])->name('address-setup.districts.destroy');
    // Villages
    Route::post('settings/address-setup/villages', [AddressSetupController::class, 'storeVillage'])->name('address-setup.villages.store');
    Route::delete('settings/address-setup/villages/{village}', [AddressSetupController::class, 'destroyVillage'])->name('address-setup.villages.destroy');
    // Streets (RT/RW)
    Route::post('settings/address-setup/streets', [AddressSetupController::class, 'storeStreet'])->name('address-setup.streets.store');
    Route::delete('settings/address-setup/streets/{street}', [AddressSetupController::class, 'destroyStreet'])->name('address-setup.streets.destroy');
    // Buildings (Gedung/Unit/Lantai)
    Route::post('settings/address-setup/buildings', [AddressSetupController::class, 'storeBuilding'])->name('address-setup.buildings.store');
    Route::delete('settings/address-setup/buildings/{building}', [AddressSetupController::class, 'destroyBuilding'])->name('address-setup.buildings.destroy');
    // Postal Codes
    Route::post('settings/address-setup/postal-codes', [AddressSetupController::class, 'storePostalCode'])->name('address-setup.postal-codes.store');
    Route::delete('settings/address-setup/postal-codes/{postalCode}', [AddressSetupController::class, 'destroyPostalCode'])->name('address-setup.postal-codes.destroy');
    // Group of houses
    Route::post('settings/address-setup/group-of-houses', [AddressSetupController::class, 'storeGroupOfHouses'])->name('address-setup.group-of-houses.store');
    Route::delete('settings/address-setup/group-of-houses/{groupOfHouse}', [AddressSetupController::class, 'destroyGroupOfHouses'])->name('address-setup.group-of-houses.destroy');
    // Land plots
    Route::post('settings/address-setup/land-plots', [AddressSetupController::class, 'storeLandPlot'])->name('address-setup.land-plots.store');
    Route::delete('settings/address-setup/land-plots/{landPlot}', [AddressSetupController::class, 'destroyLandPlot'])->name('address-setup.land-plots.destroy');
    // Parameters
    Route::post('settings/address-setup/parameters', [AddressSetupController::class, 'storeParameters'])->name('address-setup.parameters.store');
    // Hierarchy Lookups (Bottom-Up and Top-Down)
    Route::get('settings/address-setup/lookup/bottom-up', [AddressSetupController::class, 'lookupBottomUp'])->name('address-setup.lookup.bottom-up');
    Route::get('settings/address-setup/lookup/top-down', [AddressSetupController::class, 'lookupTopDown'])->name('address-setup.lookup.top-down');
    Route::get('settings/address-setup/timezone/resolve', [AddressSetupController::class, 'resolveTimezone'])->name('address-setup.timezone.resolve');
    Route::get('settings/address-setup/divisions', [AddressSetupController::class, 'getDivisions'])->name('address-setup.divisions');
    Route::get('settings/address-setup/villages-paginated', [AddressSetupController::class, 'getVillagesPaginated'])->name('address-setup.villages.paginated');
    // External Codes & Translations
    Route::get('settings/address-setup/external-codes', [AddressSetupController::class, 'getExternalCodes'])->name('address-setup.external-codes.index');
    Route::post('settings/address-setup/external-codes', [AddressSetupController::class, 'storeExternalCode'])->name('address-setup.external-codes.store');
    Route::delete('settings/address-setup/external-codes/{id}', [AddressSetupController::class, 'destroyExternalCode'])->name('address-setup.external-codes.destroy');
    Route::get('settings/address-setup/translations', [AddressSetupController::class, 'getTranslations'])->name('address-setup.translations.index');
    Route::post('settings/address-setup/translations', [AddressSetupController::class, 'storeTranslation'])->name('address-setup.translations.store');
    Route::delete('settings/address-setup/translations/{id}', [AddressSetupController::class, 'destroyTranslation'])->name('address-setup.translations.destroy');

    // Laporan cetak/ekspor untuk semua app; lihat docs/dev/23-document-rendering.md.
    Route::get('settings/report-layouts', [ReportLayoutController::class, 'page'])->name('report-layouts.index');
    Route::get('reports/exports', [ReportExportController::class, 'page'])->name('report-exports.index');
    Route::get('settings/units-of-measure', [UnitOfMeasureController::class, 'index'])->name('units-of-measure.index');
    // Presisi uang per mata uang untuk feed posting finance; lihat docs/todo/feed-posting-finance.
    Route::get('settings/currencies', [CurrencyPrecisionController::class, 'index'])->name('currencies.index');
    Route::put('settings/currencies/{currency}', [CurrencyPrecisionController::class, 'update'])->name('currencies.update');
    // Daftar akun referensi milik aplikasi finance pelanggan; lihat docs/todo/feed-posting-finance.
    Route::get('settings/finance-accounts', [ReferenceAccountController::class, 'index'])->name('finance-accounts.index');
    Route::get('settings/finance-accounts/template', [ReferenceAccountController::class, 'template'])->name('finance-accounts.template');
    // Klien integrasi: sistem di luar CoreERP yang membaca feed posting finance.
    Route::get('settings/integration-clients', [IntegrationClientController::class, 'index'])->name('integration-clients.index');
    // Vendor: party berperan vendor per entitas legal, dipakai dokumen penerimaan dan feed posting.
    Route::get('settings/vendors', [VendorController::class, 'index'])->name('vendors.index');
    Route::get('settings/workflows', [WorkflowConfigurationController::class, 'index'])->name('workflows.index');
    Route::post('settings/workflows', [WorkflowConfigurationController::class, 'store'])->name('workflows.store');
    // Didaftarkan sebelum rute ber-parameter supaya "parameters" tidak pernah terbaca sebagai id workflow.
    Route::post('settings/workflows/parameters', [WorkflowConfigurationController::class, 'updateParameters'])->name('workflows.parameters.update');
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
    Route::patch('settings/access/invitations/{invitationCode}', [InvitationCodeController::class, 'update'])
        ->name('access.invitations.update');
    Route::post('settings/access/invitations/{invitationCode}/kirim-ulang', [InvitationCodeController::class, 'resend'])
        ->middleware('throttle:10,1')
        ->name('settings.access.invitations.resend');
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
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('report-exports', [ReportExportController::class, 'index'])->name('report-exports.list');
        Route::get('report-exports/{id}/download', [ReportExportController::class, 'download'])->name('report-exports.download');
        Route::get('report-exports/{id}', [ReportExportController::class, 'show'])->name('report-exports.show');
        Route::delete('report-exports/{id}', [ReportExportController::class, 'destroy'])->name('report-exports.destroy');
        Route::get('reports/{code}/fields', [ReportController::class, 'fields'])->name('reports.fields');
        Route::get('reports/{code}/layouts', [ReportLayoutController::class, 'index'])->name('reports.layouts.index');
        Route::post('reports/{code}/layouts', [ReportLayoutController::class, 'store'])->name('reports.layouts.store');
        Route::put('reports/{code}/layout-default', [ReportLayoutController::class, 'setDefault'])->name('reports.layouts.default');
        Route::get('reports/{code}/layouts/{ref}/file', [ReportLayoutController::class, 'file'])->name('reports.layouts.file');
        Route::post('reports/{code}/layouts/{id}', [ReportLayoutController::class, 'update'])->name('reports.layouts.update');
        Route::delete('reports/{code}/layouts/{id}', [ReportLayoutController::class, 'destroy'])->name('reports.layouts.destroy');
        Route::post('reports/{code}/exports', [ReportExportController::class, 'store'])->name('reports.exports.store');
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
        // Identitas cetak: kop, footer, dan logo per organisasi; lihat docs/dev/23-document-rendering.md.
        Route::get('organizations/{organization}/locations', [OrganizationLocationController::class, 'index'])->name('organizations.locations.index');
        Route::post('organizations/{organization}/locations', [OrganizationLocationController::class, 'store'])->name('organizations.locations.store');
        Route::put('organizations/{organization}/locations/{location}', [OrganizationLocationController::class, 'update'])->name('organizations.locations.update');
        Route::delete('organizations/{organization}/locations/{location}', [OrganizationLocationController::class, 'destroy'])->name('organizations.locations.destroy');
        Route::get('organizations/{organization}/contacts', [OrganizationContactController::class, 'index'])->name('organizations.contacts.index');
        Route::post('organizations/{organization}/contacts', [OrganizationContactController::class, 'store'])->name('organizations.contacts.store');
        Route::put('organizations/{organization}/contacts/{contact}', [OrganizationContactController::class, 'update'])->name('organizations.contacts.update');
        Route::delete('organizations/{organization}/contacts/{contact}', [OrganizationContactController::class, 'destroy'])->name('organizations.contacts.destroy');
        // Setelan feed posting finance per entitas legal: aktif, cutover, dan mode penyelesaian.
        Route::get('organizations/{organization}/finance-posting', [FinancePostingSettingController::class, 'show'])->name('organizations.finance-posting.show');
        Route::put('organizations/{organization}/finance-posting', [FinancePostingSettingController::class, 'update'])->name('organizations.finance-posting.update');
        Route::post('organizations/{organization}/finance-posting/settlement-modes', [FinancePostingSettingController::class, 'storeMode'])->name('organizations.finance-posting.settlement-modes.store');
        Route::delete('organizations/{organization}/finance-posting/settlement-modes/{mode}', [FinancePostingSettingController::class, 'destroyMode'])->name('organizations.finance-posting.settlement-modes.destroy');
        Route::post('finance-reference-accounts/imports', [ReferenceAccountController::class, 'import'])
            ->middleware('throttle:20,1')
            ->name('finance-reference-accounts.imports.store');
        Route::patch('finance-reference-accounts/{account}', [ReferenceAccountController::class, 'update'])->name('finance-reference-accounts.update');
        Route::post('integration-clients', [IntegrationClientController::class, 'store'])->middleware('throttle:20,1')->name('integration-clients.store');
        Route::patch('integration-clients/{integrationClient}', [IntegrationClientController::class, 'update'])->name('integration-clients.update');
        Route::post('integration-clients/{integrationClient}/revoke', [IntegrationClientController::class, 'revoke'])->name('integration-clients.revoke');
        Route::post('integration-clients/{integrationClient}/rotate-token', [IntegrationClientController::class, 'rotateToken'])->middleware('throttle:20,1')->name('integration-clients.rotate-token');
        Route::post('integration-clients/{integrationClient}/rotate-signing-secret', [IntegrationClientController::class, 'rotateSigningSecret'])->middleware('throttle:20,1')->name('integration-clients.rotate-signing-secret');
        Route::post('integration-clients/{integrationClient}/test-push', [IntegrationClientController::class, 'testPush'])->middleware('throttle:10,1')->name('integration-clients.test-push');
        Route::get('vendors/party-options', [VendorController::class, 'partyOptions'])->name('vendors.party-options');
        Route::post('vendors', [VendorController::class, 'store'])->middleware('throttle:60,1')->name('vendors.store');
        Route::patch('vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');
        Route::get('organizations/{organization}/print-identity', [PrintIdentityController::class, 'show'])->name('organizations.print-identity.show');
        Route::put('organizations/{organization}/print-identity', [PrintIdentityController::class, 'update'])->name('organizations.print-identity.update');
        Route::post('organizations/{organization}/print-identity/logos', [PrintIdentityController::class, 'storeLogo'])->name('organizations.print-identity.logos.store');
        Route::get('organizations/{organization}/print-identity/logos/{logo}', [PrintIdentityController::class, 'logo'])->name('organizations.print-identity.logos.show');
        Route::patch('organizations/{organization}/print-identity/logos/{logo}', [PrintIdentityController::class, 'updateLogo'])->name('organizations.print-identity.logos.update');
        Route::delete('organizations/{organization}/print-identity/logos/{logo}', [PrintIdentityController::class, 'destroyLogo'])->name('organizations.print-identity.logos.destroy');
        Route::get('invitation-codes', [InvitationCodeController::class, 'index'])->name('invitation-codes.index');
        Route::post('invitation-codes', [InvitationCodeController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('invitation-codes.store');
        Route::patch('invitation-codes/{invitationCode}', [InvitationCodeController::class, 'update'])
            ->name('invitation-codes.update');
        Route::delete('invitation-codes/{invitationCode}', [InvitationCodeController::class, 'destroy'])
            ->name('invitation-codes.destroy');
        Route::get('control/identities', [IdentityMonitorController::class, 'apiIndex'])
            ->name('control.identities.index');
        Route::get('provider/apps', [AppCatalogController::class, 'index'])->name('provider.apps.index');
        Route::post('provider/apps', [AppCatalogController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('provider.apps.store');
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
