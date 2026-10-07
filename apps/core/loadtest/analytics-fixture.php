<?php

declare(strict_types=1);

use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Access\Support\DataPolicyScopeResolver;
use App\Platform\Identity\Models\User;
use App\Platform\Organization\Actions\CreateOrganization;
use App\Platform\Tenant\Actions\RegisterBusiness as RegisterBusinessTenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$root = getcwd();

if (! is_file($root.'/artisan') || ! is_file($root.'/bootstrap/app.php')) {
    throw new RuntimeException('Jalankan fixture dari direktori apps/core.');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fixtureId = preg_replace('/[^a-zA-Z0-9-]/', '-', getenv('ANALYTICS_FIXTURE_ID') ?: 'a10');
$datasetCode = getenv('ANALYTICS_DATASET') ?: '';
$policyCode = getenv('ANALYTICS_POLICY_CODE') ?: '';
$moduleId = strstr($datasetCode, '.', true);
$tenantCount = (int) (getenv('ANALYTICS_TENANTS') ?: 128);
$assetsMin = (int) (getenv('ANALYTICS_ASSETS_MIN') ?: 5000);
$assetsMax = (int) (getenv('ANALYTICS_ASSETS_MAX') ?: 20000);
$password = getenv('LOADTEST_PASSWORD') ?: 'Loadtest-Owner-2026!';
$policyRed = filter_var(getenv('ANALYTICS_POLICY_RED') ?: false, FILTER_VALIDATE_BOOL);
$outputPath = getenv('ANALYTICS_FIXTURE_OUTPUT') ?: '/results/analytics-fixture.json';
$appIds = [$moduleId];

if ($fixtureId === null || $fixtureId === '' || strlen($fixtureId) > 80
    || $moduleId === false || $moduleId === '' || $policyCode === ''
    || $tenantCount < 100 || $assetsMin < 1 || $assetsMax < $assetsMin) {
    throw new InvalidArgumentException('Setelan fixture analitik tidak sah.');
}

$moduleIds = preg_split('/\s+/', trim(getenv('LOADTEST_MODULES') ?: $moduleId), -1, PREG_SPLIT_NO_EMPTY) ?: [];

if (! in_array($moduleId, $moduleIds, true)) {
    throw new InvalidArgumentException("Module dataset {$moduleId} tidak ada di LOADTEST_MODULES.");
}

Schema::dropIfExists('lt_analytics_user_scope');
Schema::create('lt_analytics_user_scope', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->string('fixture_id', 80)->index();
    $table->ulid('tenant_id')->index();
    $table->unsignedBigInteger('user_id')->index();
    $table->string('user_email')->index();
    $table->string('access_kind', 24);
    $table->ulid('legal_entity_id')->nullable();
    $table->jsonb('unit_ids');
    $table->timestamps();
    $table->unique(['fixture_id', 'user_email']);
});

$createOrganization = app(CreateOrganization::class);
$registerBusiness = app(RegisterBusinessTenant::class);
$scopeResolver = app(DataPolicyScopeResolver::class);
$fixtureTenants = [];
$scopeRows = [];

for ($tenantIndex = 0; $tenantIndex < $tenantCount; $tenantIndex++) {
    $suffix = sprintf('%04d', $tenantIndex + 1);
    $ownerEmail = "core-load-{$fixtureId}-analytics-{$suffix}-owner@example.test";
    $owner = $registerBusiness->handle([
        'name' => "Load {$fixtureId} {$suffix} owner",
        'email' => $ownerEmail,
        'password' => $password,
        'business_name' => "Load {$fixtureId} {$suffix}",
        'app_ids' => $appIds,
    ]);
    $ownerMembership = $owner->activeMembership();

    if (! ($ownerMembership instanceof TenantMembership)) {
        throw new RuntimeException("Owner fixture {$suffix} tidak memiliki membership aktif.");
    }

    $tenantId = (string) $ownerMembership->tenant_id;
    $ownerRole = Role::query()
        ->where('tenant_id', $tenantId)
        ->where('is_owner', true)
        ->firstOrFail();

    $legalEntityIds = [];

    for ($legalIndex = 0; $legalIndex < 2; $legalIndex++) {
        $legal = $createOrganization->handle($ownerMembership, [
            'classification' => 'legal_entity',
            'name' => "Load {$fixtureId} {$suffix} LE{$legalIndex}",
            'company_code' => sprintf('LT%s%04dE%d', substr($fixtureId, 0, 12), $tenantIndex + 1, $legalIndex),
            'country_code' => 'ID',
            'operating_unit_type' => null,
        ]);
        $legalEntityIds[] = (string) $legal->id;
    }

    $orgUnitIds = [];

    for ($unitIndex = 0; $unitIndex < 8; $unitIndex++) {
        $unit = $createOrganization->handle($ownerMembership, [
            'classification' => 'operating_unit',
            'name' => "Load {$fixtureId} {$suffix} OU{$unitIndex}",
            'company_code' => null,
            'country_code' => null,
            'operating_unit_type' => 'department',
        ]);
        $orgUnitIds[] = (string) $unit->id;
    }

    $role = Role::query()->create([
        'tenant_id' => $tenantId,
        'name' => "Analytics {$fixtureId} {$suffix}",
        'is_active' => true,
        'is_owner' => false,
    ]);
    $role->duties()->sync($ownerRole->duties()->pluck('security_duties.code')->all());

    $users = [
        'owner' => ['id' => (int) $owner->id, 'email' => $ownerEmail],
        'two_units' => createUser($fixtureId, $suffix, 'two-units', $tenantId, $password, $role, $policyCode, $scopeResolver, [
            ['legal_entity_id' => $legalEntityIds[0], 'organization_id' => $orgUnitIds[0]],
            ['legal_entity_id' => $legalEntityIds[0], 'organization_id' => $orgUnitIds[1]],
        ], $policyRed),
        'no_grant' => createUser($fixtureId, $suffix, 'no-grant', $tenantId, $password, $role, $policyCode, $scopeResolver, [], false),
    ];

    $scopeRows[] = scopeRow($fixtureId, $tenantId, $users['owner'], 'all', null, $orgUnitIds);
    $scopeRows[] = scopeRow($fixtureId, $tenantId, $users['two_units'], 'two_units', $legalEntityIds[0], array_slice($orgUnitIds, 0, 2));
    $scopeRows[] = scopeRow($fixtureId, $tenantId, $users['no_grant'], 'none', null, []);

    mt_srand(crc32($fixtureId.'-'.$tenantIndex));
    $assetCount = mt_rand($assetsMin, $assetsMax);

    $fixtureTenants[] = [
        'index' => $tenantIndex,
        'tenant_id' => $tenantId,
        'legal_entity_ids' => $legalEntityIds,
        'org_unit_ids' => $orgUnitIds,
        'asset_count' => $assetCount,
        'users' => $users,
    ];

    fwrite(STDOUT, sprintf("Tenant %d/%d: fixture akses siap; %d aset disiapkan untuk module\n", $tenantIndex + 1, $tenantCount, $assetCount));
}

DB::table('lt_analytics_user_scope')->insert($scopeRows);

$encoded = json_encode([
    'fixture_id' => $fixtureId,
    'dataset' => $datasetCode,
    'policy_red' => $policyRed,
    'tenants' => $fixtureTenants,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

if (! is_dir(dirname($outputPath))) {
    throw new RuntimeException("Direktori keluaran fixture tidak ada: {$outputPath}");
}

if (file_put_contents($outputPath, $encoded) === false) {
    throw new RuntimeException("Gagal menulis manifest fixture: {$outputPath}");
}

fwrite(STDOUT, sprintf("Fixture analitik siap: %d tenant, %d pengguna, %s\n", $tenantCount, $tenantCount * 3, $outputPath));

/**
 * Buat akun uji dengan permission yang sama seperti Owner dan scope data yang dinyatakan.
 *
 * @param  list<array{legal_entity_id:?string,organization_id:?string}>  $grants
 * @return array{id:int,email:string}
 */
function createUser(
    string $fixtureId,
    string $tenantSuffix,
    string $roleName,
    string $tenantId,
    string $password,
    Role $role,
    string $policyCode,
    DataPolicyScopeResolver $scopeResolver,
    array $grants,
    bool $policyRed,
): array {
    $email = "core-load-{$fixtureId}-analytics-{$tenantSuffix}-{$roleName}@example.test";
    $user = User::query()->create([
        'name' => "Load {$fixtureId} {$tenantSuffix} {$roleName}",
        'email' => $email,
        'password' => $password,
    ]);
    $membership = TenantMembership::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $user->id,
        'status' => 'active',
    ]);
    $now = now();
    $environmentIds = DB::table('environments')
        ->where('tenant_id', $tenantId)
        ->whereNull('deleted_at')
        ->pluck('id');

    if ($environmentIds->isNotEmpty()) {
        DB::table('environment_members')->insert($environmentIds->map(fn (string $environmentId): array => [
            'environment_id' => $environmentId,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    $assignment = RoleAssignment::query()->create([
        'membership_id' => $membership->id,
        'role_id' => $role->id,
        'source' => 'manual',
        'status' => 'active',
        'valid_from' => now(),
    ]);

    if ($policyRed && $roleName === 'two-units') {
        $grants = [[
            'legal_entity_id' => null,
            'organization_id' => null,
            'unrestricted' => true,
        ]];
    }

    $scopeInputs = array_map(fn (array $grant): array => [
        'policy_code' => $policyCode,
        'legal_entity_id' => $grant['legal_entity_id'],
        'organization_id' => $grant['organization_id'],
        'hierarchy_id' => null,
        'include_descendants' => false,
        ...(($grant['unrestricted'] ?? false) ? ['unrestricted' => true] : []),
    ], $grants);
    $scopeResolver->assertNoRedundantGrants($scopeInputs);

    foreach ($scopeInputs as $input) {
        $assignment->dataPolicyScopes()->create([
            'tenant_id' => $tenantId,
            ...$scopeResolver->resolve($tenantId, $role, $input),
            'valid_from' => now(),
        ]);
    }

    return ['id' => (int) $user->id, 'email' => $email];
}

/**
 * @param  array{id:int,email:string}  $user
 * @param  list<string>  $unitIds
 * @return array<string, mixed>
 */
function scopeRow(string $fixtureId, string $tenantId, array $user, string $kind, ?string $legalEntityId, array $unitIds): array
{
    return [
        'id' => (string) Str::ulid(),
        'fixture_id' => $fixtureId,
        'tenant_id' => $tenantId,
        'user_id' => $user['id'],
        'user_email' => $user['email'],
        'access_kind' => $kind,
        'legal_entity_id' => $legalEntityId,
        'unit_ids' => json_encode($unitIds, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}
