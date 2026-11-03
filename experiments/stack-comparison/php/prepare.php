<?php

declare(strict_types=1);

use App\Foundation\FinancePosting\Models\FinancePostingSetting;
use App\Foundation\FinancePosting\Models\FinanceSettlementMode;
use App\Foundation\Vendor\Actions\SaveVendor;
use App\Platform\Access\Models\AppDataPolicy;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Access\Models\SecurityDuty;
use App\Platform\Access\Models\SecurityPrivilege;
use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\Environment\Models\Environment;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Actions\InstallModule;
use App\Platform\Organization\Actions\CreateOrganization;
use App\Platform\Organization\Actions\CreateOrganizationHierarchy;
use App\Platform\Organization\Actions\PlaceOrganizationInHierarchy;
use App\Platform\Organization\Actions\PublishOrganizationHierarchy;
use App\Platform\Organization\Models\Organization;
use App\Platform\Organization\Models\OrganizationHierarchy;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;

require __DIR__.'/bootstrap.php';

comparisonGuard();

// Satu penulis fixture saja; bukan lock runtime atau pengganti transaksi aplikasi.
$lock = fopen(comparisonFixturePath().'.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Proses fixture lain sedang berjalan.');
}

$password = getenv('COMPARE_PASSWORD');
if (! is_string($password) || strlen($password) < 12) {
    throw new RuntimeException('COMPARE_PASSWORD harus tersedia melalui environment privat, minimal 12 karakter.');
}
$tenantCount = positiveInteger('COMPARE_TENANTS', 2, 1000);
$usersPerTenant = positiveInteger('COMPARE_USERS_PER_TENANT', 4, 10000);
$moduleId = getenv('COMPARE_MODULE_ID') ?: 'management-aset';
$sourceSha = '6b3cf5846fd4442c3e895cacf2ba58275f9dd75e';

if (is_file(comparisonFixturePath())) {
    $fixture = comparisonLoad();
    if ($fixture['source_sha'] !== $sourceSha || $fixture['module_id'] !== $moduleId
        || $fixture['users_per_tenant'] !== $usersPerTenant || count($fixture['tenants']) > $tenantCount) {
        throw new RuntimeException('Konfigurasi tersimpan tidak cocok. Gunakan database eksperimen baru untuk konfigurasi berbeda.');
    }
} else {
    if (DB::table('tenants')->exists() || DB::table('users')->exists()) {
        throw new RuntimeException('Fixture baru hanya boleh dibuat pada database eksperimen tanpa tenant dan pengguna.');
    }
    $fixtureId = strtolower((string) Str::ulid());
    $fixture = [
        'schema_version' => 1, 'source_sha' => $sourceSha, 'module_id' => $moduleId,
        'fixture_id' => $fixtureId, 'users_per_tenant' => $usersPerTenant,
        'receipt_date' => getenv('COMPARE_RECEIPT_DATE') ?: now()->toDateString(),
        'currency_code' => getenv('COMPARE_CURRENCY_CODE') ?: 'IDR',
        'profile' => 'purchase-direct-payable-pending',
        'boundaries' => ['no_attachments', 'no_linked_purchase_request', 'no_opening_balance', 'no_grant', 'no_cancellation'],
        'password_source' => 'COMPARE_PASSWORD', 'accounts' => [], 'probe_accounts' => [], 'tenants' => [],
    ];
}

for ($index = count($fixture['tenants']) + 1; $index <= $tenantCount; $index++) {
    $fixture['tenants'][] = tenantPlan($fixture, $index);
}
comparisonOwnsDatabase($fixture);
comparisonSave($fixture);

// Katalog sudah dipaku pada source_sha. Mengulang registrasi pada source ini juga
// menilai duty custom fixture sebagai duty manifest yang hilang; jangan menghapusnya.
if (! DB::table('apps')->where('id', $moduleId)->exists()
    && Artisan::call('app:register-manifest', ['module' => $moduleId]) !== 0) {
    throw new RuntimeException('Pendaftaran manifest gagal; periksa output command di runtime eksperimen.');
}

// Hash memakai konfigurasi produksi aktual, sekali di luar waktu operasi pembanding.
$hash = Hash::make($password);
foreach ($fixture['tenants'] as $index => &$tenant) {
    $existing = Tenant::query()->find($tenant['tenant_id']);
    if ($existing === null) {
        app(RegisterBusiness::class)->forOperator([
            'tenant_id' => $tenant['tenant_id'], 'name' => $tenant['owner_name'],
            'email' => $tenant['owner_email'], 'password_hash' => $hash,
            'business_name' => $tenant['tenant_name'], 'app_ids' => [$moduleId],
            'first_environment' => 'production', 'first_environment_hosting' => 'provider',
        ], null);
    } elseif ($existing->name !== $tenant['tenant_name']) {
        throw new RuntimeException('Tenant tersimpan berbeda dari rencana fixture.');
    }

    $owner = User::query()->where('email', $tenant['owner_email'])->firstOrFail();
    if (! Hash::check($password, $owner->password)) {
        throw new RuntimeException('COMPARE_PASSWORD tidak cocok dengan akun fixture yang sudah ada.');
    }
    $membership = TenantMembership::query()->where('tenant_id', $tenant['tenant_id'])->where('user_id', $owner->id)->firstOrFail();
    $environment = Environment::query()->where('tenant_id', $tenant['tenant_id'])->where('kind', 'production')->firstOrFail();
    if ($environment->database_name !== null || $environment->status !== 'active' || $environment->hosting !== 'provider') {
        throw new RuntimeException('Lingkungan fixture bukan production/provider bersama yang aktif.');
    }
    // Memulihkan setup yang sempat terhenti sesudah commit tenant, tanpa enrollment tamu.
    app(InstallModule::class)->handle($moduleId, $tenant['tenant_id'], $environment);

    AuditActor::runAs($owner->id, function () use (&$tenant, $membership, $moduleId, $hash, $usersPerTenant, &$fixture): void {
        DB::transaction(function () use (&$tenant, $membership, $moduleId, $hash, $usersPerTenant, &$fixture): void {
            $tenant['legal_entity_id'] = organization($membership, $tenant, 'company', 'legal_entity');
            $tenant['business_unit_id'] = organization($membership, $tenant, 'business_unit', 'business_unit');
            $tenant['department_id'] = organization($membership, $tenant, 'department', 'department');
            $tenant['outside_department_id'] = organization($membership, $tenant, 'outside_department', 'department');
            $hierarchy = OrganizationHierarchy::query()->where('tenant_id', $tenant['tenant_id'])->where('name', $tenant['hierarchy_name'])->first();
            if ($hierarchy === null) {
                $hierarchy = app(CreateOrganizationHierarchy::class)->handle($membership, [
                    'name' => $tenant['hierarchy_name'], 'purpose_codes' => ['management'],
                    'root_organization_id' => $tenant['legal_entity_id'], 'effective_from' => $fixture['receipt_date'],
                ]);
            }
            $version = $hierarchy->versions()->firstOrFail();
            if ($version->status === 'draft') {
                foreach ([[$tenant['business_unit_id'], $tenant['legal_entity_id']], [$tenant['department_id'], $tenant['business_unit_id']], [$tenant['outside_department_id'], $tenant['business_unit_id']]] as [$child, $parent]) {
                    if (! $version->nodes()->where('organization_id', $child)->exists()) {
                        app(PlaceOrganizationInHierarchy::class)->handle($membership, $version, $child, $parent);
                    }
                }
                app(PublishOrganizationHierarchy::class)->handle($membership, $version);
            }
            $tenant['hierarchy_id'] = $hierarchy->id;
            $tenant['hierarchy_version_id'] = $version->id;

            FinancePostingSetting::query()->updateOrCreate(['legal_entity_id' => $tenant['legal_entity_id']], [
                'tenant_id' => $tenant['tenant_id'], 'enabled' => true, 'cutover_date' => $fixture['receipt_date'],
            ]);
            FinanceSettlementMode::query()->firstOrCreate([
                'tenant_id' => $tenant['tenant_id'], 'legal_entity_id' => $tenant['legal_entity_id'],
                'effective_from' => $fixture['receipt_date'],
            ], ['mode' => 'direct_payable']);
            fixtureRow('currency_precisions', ['tenant_id' => $tenant['tenant_id'], 'currency_code' => $fixture['currency_code']], [
                'id' => (string) Str::ulid(), 'amount_decimals' => 2, 'unit_amount_decimals' => 3,
            ]);
            $vendor = app(SaveVendor::class)->create($membership, [
                'legal_entity_id' => $tenant['legal_entity_id'], 'party_id' => null, 'party_type' => 'organization',
                'party_name' => $tenant['vendor_name'], 'number' => null, 'tax_number' => null, 'status' => 'active',
            ], $tenant['creation_key'].':vendor');
            $tenant['vendor_id'] = $vendor->id;

            seedAssetConfiguration($tenant, $fixture);
            seedAccounts($tenant, $fixture);
            seedUsers($tenant, $membership, $moduleId, $hash, $usersPerTenant, $fixture);
            $tenant['receipt_body'] = receiptBody($tenant, $fixture);
            $tenant['environment_id'] = Environment::query()->where('tenant_id', $tenant['tenant_id'])->where('kind', 'production')->value('id');
            $tenant['seeded'] = true;
        });
    });
    $fixture['tenants'][$index] = $tenant;
    comparisonSave($fixture);
    comparisonReport(['tenant_index' => $index + 1, 'seeded' => true]);
}
unset($tenant);

$fixture['bcrypt_cost'] = (int) config('hashing.bcrypt.rounds');
$fixture['seeded_at'] = now()->toIso8601String();
comparisonSave($fixture);
comparisonReport(['status' => 'prepared', 'tenants' => count($fixture['tenants']), 'accounts' => count($fixture['accounts']), 'probe_accounts' => count($fixture['probe_accounts']), 'fixture_path' => comparisonFixturePath(), 'password_exported' => false, 'bcrypt_cost' => $fixture['bcrypt_cost']]);

function positiveInteger(string $name, int $default, int $maximum): int
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    if (! ctype_digit($value) || (int) $value < 1 || (int) $value > $maximum) {
        throw new RuntimeException($name.' di luar rentang fixture.');
    }

    return (int) $value;
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function tenantPlan(array $fixture, int $index): array
{
    $key = $fixture['fixture_id'].'-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    $tenant = [
        'fixture_index' => $index, 'tenant_id' => (string) Str::ulid(), 'creation_key' => 'comparison:'.$key,
        'tenant_name' => 'Usaha simulasi '.$key, 'owner_name' => 'Pengguna simulasi '.$key,
        'owner_email' => 'owner-'.$key.'@comparison.invalid', 'company_name' => 'Perusahaan simulasi '.$key,
        'business_unit_name' => 'Unit usaha simulasi '.$key, 'department_name' => 'Departemen simulasi '.$key,
        'outside_department_name' => 'Departemen pembatas '.$key, 'hierarchy_name' => 'Struktur simulasi '.$key,
        'vendor_name' => 'Pemasok simulasi '.$key, 'asset_name' => 'Aset simulasi '.$key,
        'company_code' => 'C'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
        'business_unit_code' => 'BU'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
        'department_code' => 'DP'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
        'outside_department_code' => 'DX'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
        'attribute_value' => '12.500000', 'attribute_data_type' => 'decimal',
        'names' => [], 'accounts' => [],
    ];
    foreach (['group', 'asset_type', 'location', 'book', 'profile', 'classification', 'attribute', 'group_book', 'type_attribute', 'posting_group'] as $resource) {
        $tenant[$resource.'_id'] = (string) Str::ulid();
        $tenant['names'][$resource] = ucfirst(str_replace('_', ' ', $resource)).' simulasi '.$key;
    }
    foreach (['acquisition', 'payable', 'tax', 'accumulated', 'depreciation'] as $resource) {
        $tenant['accounts'][$resource] = ['id' => (string) Str::ulid(), 'code' => 'CMP-'.strtoupper($resource), 'name' => 'Akun simulasi '.$resource.' '.$key];
    }

    return $tenant;
}

/** @param array<string, mixed> $tenant */
function organization(TenantMembership $actor, array $tenant, string $kind, string $classification): string
{
    $name = $tenant[$kind.'_name'];
    $existing = Organization::query()->where('tenant_id', $actor->tenant_id)->where('name', $name)->first();
    if ($existing !== null) {
        return $existing->id;
    }

    return app(CreateOrganization::class)->handle($actor, [
        'classification' => $classification === 'legal_entity' ? 'legal_entity' : 'operating_unit', 'name' => $name,
        'company_code' => $classification === 'legal_entity' ? $tenant['company_code'] : null,
        'country_code' => $classification === 'legal_entity' ? 'ID' : null,
        'operating_unit_type' => $classification === 'legal_entity' ? null : $classification,
        'operating_unit_number' => $classification === 'legal_entity' ? null : $tenant[$kind.'_code'],
    ])->id;
}

/** @param array<string, mixed> $key @param array<string, mixed> $data */
function fixtureRow(string $table, array $key, array $data): void
{
    if (DB::table($table)->where($key)->exists()) {
        return;
    }
    DB::table($table)->insert([...$key, ...$data, 'created_at' => now(), 'updated_at' => now()]);
}

/** @param array<string, mixed> $tenant @param array<string, mixed> $data */
function assetRow(string $table, array $tenant, string $resource, array $data): void
{
    fixtureRow($table, ['id' => $tenant[$resource.'_id'], 'tenant_id' => $tenant['tenant_id']], $data);
}

/** @param array<string, mixed> $tenant @param array<string, mixed> $fixture */
function seedAssetConfiguration(array $tenant, array $fixture): void
{
    assetRow('aset_m_kelompok_harta_fiskal', $tenant, 'classification', [
        'template_key' => $tenant['creation_key'].':classification', 'kode' => 'CMP', 'jurisdiction' => 'ID',
        'label' => $tenant['names']['classification'], 'effective_from' => $fixture['receipt_date'],
        'useful_life_years' => 4, 'straight_line_rate_percent' => '25.0000', 'allow_straight_line' => true,
        'allow_reducing_balance' => false, 'depreciable' => true, 'aktif' => true,
    ]);
    $master = fn (string $resource, string $code): array => [
        'creation_key' => $tenant['creation_key'].':'.$resource, 'kode' => $code, 'nama' => $tenant['names'][$resource], 'aktif' => true,
    ];
    assetRow('aset_m_lokasi_aset', $tenant, 'location', [...$master('location', 'CMPLOC'), 'org_unit_id' => $tenant['department_id'], 'departemen_bawaan_id' => $tenant['department_id']]);
    assetRow('aset_m_group_aset', $tenant, 'group', [...$master('group', 'CMPGRP'), 'kelompok_harta_fiskal_id' => $tenant['classification_id'], 'property_type' => 'fixed_aset', 'lokasi_aset_id' => $tenant['location_id'], 'capitalization_threshold' => '0.00']);
    assetRow('aset_m_jenis_aset', $tenant, 'asset_type', $master('asset_type', 'CMPTYP'));
    assetRow('aset_m_profil_penyusutan', $tenant, 'profile', [
        ...$master('profile', 'CMPSL48'), 'method' => 'straight_line', 'frequency' => 'monthly',
        'year_basis' => 'calendar', 'convention' => 'full_month', 'useful_life_periods' => 48,
        'effective_from' => $fixture['receipt_date'],
    ]);
    assetRow('aset_m_buku_penyusutan', $tenant, 'book', [...$master('book', 'CMPCUR'), 'posting_layer' => 'current', 'depreciation_profile_id' => $tenant['profile_id']]);
    assetRow('aset_m_group_buku_penyusutan', $tenant, 'group_book', [
        'group_aset_id' => $tenant['group_id'], 'buku_id' => $tenant['book_id'], 'depreciate' => true,
        'useful_life_periods' => 48, 'convention' => 'full_month',
    ]);
    assetRow('aset_m_tipe_atribut', $tenant, 'attribute', [...$master('attribute', 'CMPDEC'), 'data_type' => 'decimal', 'min_value' => '0.000000', 'max_value' => '1000.000000']);
    assetRow('aset_m_jenis_aset_atribut', $tenant, 'type_attribute', [
        'jenis_aset_id' => $tenant['asset_type_id'], 'tipe_atribut_id' => $tenant['attribute_id'], 'wajib' => true, 'urutan' => 1,
    ]);
}

/** @param array<string, mixed> $tenant */
function seedAccounts(array $tenant, array $fixture): void
{
    foreach ($tenant['accounts'] as $kind => $account) {
        fixtureRow('finance_reference_accounts', ['id' => $account['id'], 'tenant_id' => $tenant['tenant_id']], [
            'legal_entity_id' => null, 'external_id' => $tenant['creation_key'].':'.$kind,
            'code' => $account['code'], 'name' => $account['name'], 'type' => $kind === 'depreciation' ? 'profit_loss' : 'balance_sheet', 'active' => true,
        ]);
    }
    assetRow('aset_m_posting_group', $tenant, 'posting_group', [
        'group_aset_id' => $tenant['group_id'], 'effective_from' => $fixture['receipt_date'],
        'acquisition_account_id' => $tenant['accounts']['acquisition']['id'], 'payable_account_id' => $tenant['accounts']['payable']['id'],
        'input_vat_account_id' => $tenant['accounts']['tax']['id'], 'accumulated_depreciation_account_id' => $tenant['accounts']['accumulated']['id'],
        'depreciation_expense_account_id' => $tenant['accounts']['depreciation']['id'],
    ]);
}

/** @param array<string, mixed> $tenant @param array<string, mixed> $fixture */
function seedUsers(array $tenant, TenantMembership $owner, string $moduleId, string $hash, int $count, array &$fixture): void
{
    $ownerRole = Role::query()->where('tenant_id', $tenant['tenant_id'])->where('is_owner', true)->firstOrFail();
    $role = Role::query()->firstOrCreate(['tenant_id' => $tenant['tenant_id'], 'name' => 'Pelaksana simulasi '.$tenant['fixture_index']], ['is_active' => true, 'is_owner' => false]);
    $role->duties()->sync($ownerRole->duties()->where('app_id', $moduleId)->pluck('code')->all());
    $fixture['accounts'] = array_values(array_filter($fixture['accounts'], fn (array $account): bool => $account['tenant_id'] !== $tenant['tenant_id']));
    $fixture['probe_accounts'] = array_values(array_filter($fixture['probe_accounts'], fn (array $account): bool => $account['tenant_id'] !== $tenant['tenant_id']));

    for ($number = 1; $number <= $count; $number++) {
        $user = $number === 1 ? $owner->user : fixtureUser($tenant, 'worker-'.$number, $hash);
        $membership = $number === 1 ? $owner : fixtureMembership($tenant, $user, $role);
        $fixture['accounts'][] = accountContract($tenant, $user, $membership, $number, 'positive');
    }
    foreach (['no_asset_create', 'outside_scope'] as $probe) {
        $probeRole = Role::query()->firstOrCreate(['tenant_id' => $tenant['tenant_id'], 'name' => 'Probe '.$probe.' '.$tenant['fixture_index']], ['is_active' => true, 'is_owner' => false]);
        $code = 'comparison.'.$tenant['tenant_id'].'.'.$probe;
        $privilege = SecurityPrivilege::query()->firstOrCreate(['code' => $code], ['app_id' => $moduleId, 'tenant_id' => $tenant['tenant_id'], 'name' => 'Probe simulasi '.$probe, 'source' => 'custom', 'status' => 'active']);
        $permissions = [$moduleId.'.penerimaan-aset.read', $moduleId.'.penerimaan-aset.create', $moduleId.'.penerimaan-aset.update'];
        if ($probe === 'outside_scope') {
            $permissions[] = $moduleId.'.aset.create';
            $permissions[] = $moduleId.'.aset.read';
        }
        $privilege->permissions()->sync($permissions);
        $duty = SecurityDuty::query()->firstOrCreate(['code' => $code], ['app_id' => $moduleId, 'tenant_id' => $tenant['tenant_id'], 'name' => 'Probe simulasi '.$probe, 'source' => 'custom', 'status' => 'active']);
        $duty->privileges()->sync([$code]);
        $probeRole->duties()->sync([$code]);
        $user = fixtureUser($tenant, $probe, $hash);
        $membership = fixtureMembership($tenant, $user, $probeRole, $probe === 'outside_scope' ? $tenant['outside_department_id'] : null);
        $fixture['probe_accounts'][] = [
            ...accountContract($tenant, $user, $membership, 0, $probe),
            'department_id' => $probe === 'outside_scope' ? $tenant['outside_department_id'] : $tenant['department_id'],
            'role_id' => $probeRole->id, 'duty_code' => $code, 'privilege_code' => $code,
        ];
    }
    $applicationUser = fixtureUser($tenant, 'application', $hash);
    $applicationUser->forceFill(['account_type' => User::APPLICATION])->save();
    $fixture['probe_accounts'][] = [
        'email' => $applicationUser->email, 'user_id' => $applicationUser->id, 'tenant_id' => $tenant['tenant_id'],
        'fixture_index' => $tenant['fixture_index'], 'kind' => 'application',
    ];
}

/** @param array<string, mixed> $tenant */
function fixtureUser(array $tenant, string $kind, string $hash): User
{
    $email = $kind.'-'.substr($tenant['creation_key'], strlen('comparison:')).'@comparison.invalid';
    $user = User::query()->firstOrCreate(['email' => $email], ['name' => 'Pengguna simulasi '.$kind.' '.$tenant['fixture_index'], 'password' => $hash]);
    $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

    return $user;
}

/** @param array<string, mixed> $tenant */
function fixtureMembership(array $tenant, User $user, Role $role, ?string $unit = null): TenantMembership
{
    $membership = TenantMembership::query()->firstOrCreate(['tenant_id' => $tenant['tenant_id'], 'user_id' => $user->id], ['status' => 'active']);
    $assignment = RoleAssignment::query()->firstOrCreate(['membership_id' => $membership->id, 'role_id' => $role->id], ['source' => 'manual', 'status' => 'active', 'valid_from' => now()->subDay()]);
    foreach (AppDataPolicy::query()->where('app_id', getenv('COMPARE_MODULE_ID') ?: 'management-aset')->get() as $policy) {
        $assignment->dataPolicyScopes()->firstOrCreate(['policy_code' => $policy->code], [
            'tenant_id' => $tenant['tenant_id'], 'legal_entity_id' => $unit === null ? null : $tenant['legal_entity_id'],
            'organization_id' => $unit, 'include_descendants' => false, 'valid_from' => now()->subDay(),
        ]);
    }
    DB::table('environment_members')->insertOrIgnore([
        'environment_id' => Environment::query()->where('tenant_id', $tenant['tenant_id'])->where('kind', 'production')->value('id'),
        'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $membership;
}

/** @param array<string, mixed> $tenant @return array<string, mixed> */
function accountContract(array $tenant, User $user, TenantMembership $membership, int $number, string $kind): array
{
    return [
        'email' => $user->email, 'user_id' => $user->id, 'membership_id' => $membership->id,
        'tenant_id' => $tenant['tenant_id'], 'legal_entity_id' => $tenant['legal_entity_id'], 'department_id' => $tenant['department_id'],
        'fixture_index' => $tenant['fixture_index'], 'user_index' => $number, 'kind' => $kind,
    ];
}

/** @param array<string, mixed> $tenant @param array<string, mixed> $fixture @return array<string, mixed> */
function receiptBody(array $tenant, array $fixture): array
{
    return [
        'legal_entity_id' => $tenant['legal_entity_id'], 'responsible_org_unit_id' => $tenant['department_id'],
        'receiving_org_unit_id' => $tenant['department_id'], 'tanggal' => $fixture['receipt_date'],
        'tanggal_siap_pakai' => $fixture['receipt_date'], 'currency_code' => $fixture['currency_code'],
        'vendor_id' => $tenant['vendor_id'], 'vendor_invoice_date' => $fixture['receipt_date'],
        'lokasi_aset_id' => $tenant['location_id'], 'cara_perolehan' => AcquisitionMethod::PURCHASE,
        'details' => [[
            'nama' => $tenant['asset_name'], 'group_aset_id' => $tenant['group_id'], 'jenis_aset_id' => $tenant['asset_type_id'],
            'jumlah' => 3, 'nilai_per_unit' => '333333.333', 'ppn_per_unit' => '33333.333',
            'atribut' => [['tipe_atribut_id' => $tenant['attribute_id'], 'nilai' => $tenant['attribute_value']]],
        ]],
    ];
}
