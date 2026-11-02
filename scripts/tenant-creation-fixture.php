<?php

declare(strict_types=1);

use App\Platform\ControlPlane\Models\ProductCustomer;
use App\Platform\ControlPlane\Models\ProductCustomerSession;
use App\Platform\Identity\Models\ExternalIdentity;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Models\CoreApp;
use App\Platform\Modules\Models\ProviderAccess;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function refuse(string $reason): never
{
    fwrite(STDERR, json_encode(['error' => $reason], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

function erpObservation(Connection $connection, string $prefix): ?array
{
    $tenant = $connection->table('tenants')->where('name', $prefix.'-erp')->first(['id', 'status']);
    if ($tenant === null) {
        return null;
    }
    $owner = $connection->table('users')->where('email', $prefix.'-erp-owner@example.invalid')->first(['id', 'must_change_password']);
    $operator = $connection->table('users')->where('email', $prefix.'-operator@example.invalid')->value('id');
    $membership = $connection->table('tenant_memberships')->where('tenant_id', $tenant->id)->first(['id', 'user_id', 'status']);

    return [
        'tenant_id' => $tenant->id, 'tenant_status' => $tenant->status,
        'principal_id' => $owner?->id, 'membership_id' => $membership?->id,
        'membership_principal_id' => $membership?->user_id, 'membership_status' => $membership?->status,
        'membership_count' => $connection->table('tenant_memberships')->where('tenant_id', $tenant->id)->count(),
        'owner_role_count' => $connection->table('roles')->where('tenant_id', $tenant->id)->where('is_owner', true)->count(),
        'owner_assignment_count' => $connection->table('role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('r.tenant_id', $tenant->id)->where('r.is_owner', true)->where('a.membership_id', $membership?->id)->where('a.status', 'active')->count(),
        'units_count' => $connection->table('units_of_measure')->where('tenant_id', $tenant->id)->count(),
        'entitlement_app_ids' => $connection->table('tenant_app_entitlements')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('app_id')->pluck('app_id')->all(),
        'environment_count' => $connection->table('environments')->where('tenant_id', $tenant->id)->count(),
        'module_installation_count' => $connection->table('core_module_installations')->where('tenant_id', $tenant->id)->count(),
        'operator_membership_count' => $connection->table('tenant_memberships')->where('tenant_id', $tenant->id)->where('user_id', $operator)->count(),
        'operator_principal_id' => $operator, 'must_change_password' => $owner?->must_change_password,
    ];
}

$corePath = dirname(__DIR__).'/apps/core';
$database = getenv('DB_TEST_DATABASE');
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql_test'
    || ! is_string($database) || preg_match('/^core_erp_pf01_test(?:_[a-z0-9_]+)?$/D', $database) !== 1
    || getenv('DB_TEST_HOST') !== 'database'
    || ! in_array(getenv('DB_TEST_SCHEMA'), [false, '', 'coreerp_test'], true)
    || ! in_array(getenv('COREERP_CONTROL_CONNECTION'), [false, '', 'pgsql_test'], true)
    || ! in_array(getenv('DB_URL'), [false, ''], true)
    || ! in_array(getenv('LARAVEL_PARALLEL_TESTING'), [false, '', '0'], true)
    || ! in_array(getenv('TEST_TOKEN'), [false, ''], true)) {
    refuse('PF01 requires its dedicated testing database and compose database host.');
}
if (is_file($corePath.'/bootstrap/cache/config.php')) {
    refuse('PF01 helper requires uncached application configuration.');
}

try {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($input) || ! is_string($input['run_id'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $input['run_id']) !== 1
        || ! in_array($argv[1] ?? null, ['setup', 'snapshot', 'erp-baseline', 'erp-snapshot', 'verify-erp-password'], true)) {
        refuse('Use a supported PF01 fixture verb with a hexadecimal run_id in JSON stdin.');
    }

    require $corePath.'/vendor/autoload.php';
    $app = require $corePath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $config = config('database.connections.pgsql_test');
    if (config('app.env') !== 'testing' || config('database.default') !== 'pgsql_test'
        || ($config['driver'] ?? null) !== 'pgsql' || ($config['database'] ?? null) !== $database
        || ($config['host'] ?? null) !== 'database' || ($config['search_path'] ?? null) !== 'coreerp_test'
        || ! in_array(config('coreerp.control_connection'), [null, '', 'pgsql_test'], true)) {
        refuse('Application configuration does not match the dedicated PF01 fixture.');
    }

    $prefix = 'pf01-'.substr($input['run_id'], 0, 12);
    $connection = DB::connection('pgsql_test');
    $metadata = (array) $connection->selectOne('SELECT current_database() AS database, current_schema() AS schema, current_setting(\'server_version\') AS postgres_version');
    if ($metadata['database'] !== $database || $metadata['schema'] !== 'coreerp_test') {
        refuse('Connected database or schema differs from the PF01 fixture.');
    }
    $metadata['source_hashes'] = [];
    foreach (['app/Platform/Tenant/Actions/RegisterBusiness.php', 'app/Platform/ControlPlane/Tenants/CreateTenant.php', 'app/Platform/Tenant/Actions/BootstrapErpTenant.php', 'app/Foundation/UnitOfMeasure/Listeners/ProvisionUnitsForNewTenant.php'] as $source) {
        if (is_file($corePath.'/'.$source)) {
            $metadata['source_hashes'][$source] = hash_file('sha256', $corePath.'/'.$source);
        }
    }

    if (in_array($argv[1], ['setup', 'erp-baseline'], true)) {
        $fixtureAppId = getenv('PF01_ERP_FIXTURE_APP_ID');
        if (! is_string($fixtureAppId) || preg_match('/^pf01-[a-f0-9]{16}$/D', $fixtureAppId) !== 1) {
            refuse('Configure the shared synthetic catalog fixture ID before ERP verification.');
        }
        CoreApp::query()->firstOrCreate(['id' => $fixtureAppId], [
            'name' => $fixtureAppId, 'version' => '1.0.0', 'status' => 'available',
            'database_name' => null, 'has_ui' => true, 'navigation' => [],
        ]);
        if (! $connection->table('number_sequence_profiles')->exists()) {
            app(NumberSequenceProfileSeeder::class)->run();
        }
    }

    if ($argv[1] === 'erp-baseline') {
        $operator = User::create(['name' => $prefix.'-operator', 'email' => $prefix.'-operator@example.invalid', 'password' => Str::random(64)]);
        ProviderAccess::create(['user_id' => $operator->id, 'role' => 'provider_admin']);
        $result = ['people' => ['operator' => $operator->id]];
    } elseif ($argv[1] === 'erp-snapshot') {
        $result = $connection->transaction(function () use ($connection, $prefix): array {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $totals = [];
            foreach (['clients', 'tenants', 'users', 'tenant_memberships', 'roles', 'role_assignments', 'units_of_measure', 'tenant_app_entitlements', 'environments', 'environment_members', 'core_module_installations'] as $table) {
                $totals[$table] = $connection->table($table)->count();
            }

            return ['totals' => $totals, 'erp' => erpObservation($connection, $prefix)];
        });
    } elseif ($argv[1] === 'verify-erp-password') {
        if (! is_string($input['temporary_password'] ?? null) || strlen($input['temporary_password']) > 512) {
            refuse('Password verification requires the private ERP response in JSON stdin.');
        }
        $owner = $connection->table('users')->where('email', $prefix.'-erp-owner@example.invalid')->first(['password']);
        $result = ['password_matches' => $owner !== null && Hash::check($input['temporary_password'], $owner->password)];
    } elseif ($argv[1] === 'setup') {
        $result = $connection->transaction(function () use ($prefix): array {
            $people = [];
            $customers = [];
            $sessions = [];
            $issuer = 'https://'.$prefix.'.invalid';
            $createUser = function (string $label, ?string $email = null) use ($prefix): User {
                return User::create([
                    'name' => $prefix.'-'.$label,
                    'email' => $email ?? $prefix.'-'.$label.'@example.invalid',
                    'password' => Str::random(64),
                ]);
            };
            $operator = $createUser('operator');
            $people['operator'] = $operator->id;
            ProviderAccess::create(['user_id' => $operator->id, 'role' => 'provider_admin']);

            foreach (['existing', 'new', 'same_email_a', 'same_email_b', 'race', 'perf_0', 'perf_1', 'perf_2', 'perf_3', 'perf_4'] as $label) {
                $verifiedEmail = $prefix.'-'.$label.'@example.invalid';
                $subject = (string) Str::uuid();
                $customer = ProductCustomer::create([
                    'id' => (string) Str::uuid(),
                    'identity_hash' => hash('sha256', json_encode([$issuer, $subject], JSON_THROW_ON_ERROR)),
                    'issuer' => $issuer, 'subject' => $subject, 'name' => $prefix.'-'.$label,
                    'email' => str_starts_with($label, 'same_email_') ? $prefix.'-provider@example.invalid' : $verifiedEmail,
                    'verified_email' => $verifiedEmail, 'email_verified_at' => now(),
                ]);
                if (in_array($label, ['existing', 'race'], true) || str_starts_with($label, 'perf_')) {
                    $principal = $createUser($label, $verifiedEmail);
                    $people[$label] = $principal->id;
                    ExternalIdentity::create(['user_id' => $principal->id, 'issuer' => $issuer, 'subject' => $subject, 'email_at_link' => $verifiedEmail]);
                }
                $customers[$label] = $customer->id;
                $token = bin2hex(random_bytes(32));
                ProductCustomerSession::create(['token_hash' => hash('sha256', $token), 'customer_id' => $customer->id, 'expires_at' => now()->addHours(2)]);
                $sessions[$label] = $token;
            }

            $collisionEmail = $prefix.'-collision@example.invalid';
            $collisionPrincipal = $createUser('collision', $collisionEmail);
            $people['collision'] = $collisionPrincipal->id;
            ExternalIdentity::create(['user_id' => $collisionPrincipal->id, 'issuer' => $issuer, 'subject' => (string) Str::uuid(), 'email_at_link' => $collisionEmail]);
            $subject = (string) Str::uuid();
            $customer = ProductCustomer::create([
                'id' => (string) Str::uuid(), 'identity_hash' => hash('sha256', json_encode([$issuer, $subject], JSON_THROW_ON_ERROR)),
                'issuer' => $issuer, 'subject' => $subject, 'name' => $prefix.'-collision',
                'email' => $collisionEmail, 'verified_email' => $collisionEmail, 'email_verified_at' => now(),
            ]);
            $customers['collision'] = $customer->id;
            $token = bin2hex(random_bytes(32));
            ProductCustomerSession::create(['token_hash' => hash('sha256', $token), 'customer_id' => $customer->id, 'expires_at' => now()->addHours(2)]);
            $sessions['collision'] = $token;

            return ['people' => $people, 'customers' => $customers, 'sessions' => $sessions];
        });
    } else {
        $result = $connection->transaction(function () use ($connection, $prefix): array {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $tables = [
                'clients', 'tenants', 'users', 'external_identities', 'product_customers',
                'tenant_memberships', 'tenant_creation_requests', 'operator_audit_events',
                'organizations', 'legal_entities', 'operating_units', 'tenant_app_entitlements',
                'environments', 'environment_members', 'roles', 'role_assignments',
                'units_of_measure', 'uom_classes', 'uom_systems', 'uom_translations', 'uom_conversions', 'uom_external_codes',
                'tenant_number_sequences', 'number_sequence_profiles', 'number_sequence_counters',
                'number_sequence_allocations', 'number_sequence_reservations', 'number_sequence_reusable_numbers',
                'number_sequence_issues', 'number_sequence_audit_events', 'number_sequence_continuous_pool',
                'core_module_installations', 'managed_product_tenants',
            ];
            $totals = [];
            foreach ($tables as $table) {
                $totals[$table] = $connection->table($table)->count();
            }
            $tenants = $connection->table('tenants')->where('slug', 'like', $prefix.'-%')
                ->orderBy('id')->get(['id', 'client_id', 'name', 'slug', 'status']);
            $tenantIds = $tenants->pluck('id')->all();
            $tenantOnlyIds = $tenants->reject(fn (object $tenant): bool => $tenant->name === $prefix.'-erp')->pluck('id')->all();
            $customers = $connection->select(
                'SELECT c.id, substring(c.name from ?) AS label, c.user_id, '
                .'(SELECT count(*) FROM external_identities i WHERE i.issuer = c.issuer AND i.subject = c.subject AND i.deleted_at IS NULL) AS identity_count, '
                .'(SELECT min(i.user_id) FROM external_identities i WHERE i.issuer = c.issuer AND i.subject = c.subject AND i.deleted_at IS NULL) AS identity_user_id '
                .'FROM product_customers c WHERE c.name LIKE ? ORDER BY c.name',
                [strlen($prefix) + 2, $prefix.'-%'],
            );
            $identityPair = $connection->selectOne(
                'SELECT a.email = b.email AS same_provider_email, a.subject <> b.subject AS different_subjects, '
                .'a.verified_email <> b.verified_email AS different_verified_contacts '
                .'FROM product_customers a JOIN product_customers b ON b.name = ? WHERE a.name = ?',
                [$prefix.'-same_email_b', $prefix.'-same_email_a'],
            );
            $effects = [];
            foreach (['organizations', 'legal_entities', 'operating_units', 'tenant_app_entitlements', 'environments', 'roles', 'units_of_measure', 'tenant_number_sequences', 'core_module_installations'] as $table) {
                $effects[$table] = $connection->table($table)->whereIn('tenant_id', $tenantOnlyIds)->count();
            }

            return [
                'totals' => $totals, 'tenants' => $tenants,
                'memberships' => $connection->table('tenant_memberships')->whereIn('tenant_id', $tenantIds)
                    ->orderBy('id')->get(['id', 'tenant_id', 'user_id', 'status']),
                'receipts' => $connection->table('tenant_creation_requests')->whereIn('tenant_id', $tenantIds)
                    ->orderBy('id')->get(['id', 'actor_scope', 'actor_user_id', 'principal_id', 'tenant_id', 'membership_id', 'idempotency_key']),
                'audits' => $connection->table('operator_audit_events')->where('action', 'tenant.created')->whereIn('subject_id', $tenantIds)
                    ->orderBy('id')->get(['user_id', 'subject_id', 'detail']),
                'customers' => $customers, 'identity_pair' => $identityPair, 'tenant_effects' => $effects,
                'erp' => erpObservation($connection, $prefix),
            ];
        });
    }

    echo json_encode(['metadata' => $metadata, ...$result], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['error' => 'PF01 fixture failed.', 'type' => get_class($failure)], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
