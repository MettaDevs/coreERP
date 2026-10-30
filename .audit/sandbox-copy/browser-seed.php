<?php

declare(strict_types=1);

use App\Platform\ControlPlane\Models\Client;
use App\Platform\Environment\Models\Environment;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Identity\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$location = DB::selectOne('select current_database() as db, current_schema() as schema');
if ($location->db !== 'core_erp_test' || $location->schema !== 'coreerp_test') throw new RuntimeException('Browser fixture may only use isolated CI database');
if (Artisan::call('migrate', ['--database' => (string) config('database.default'), '--force' => true]) !== 0) throw new RuntimeException(Artisan::output());
$slug = 'browser-'.strtolower((string) Str::ulid());
$operator = User::create(['name' => 'Operator browser', 'email' => $slug.'@example.test', 'password' => bcrypt((string) getenv('COPY_BROWSER_PASSWORD'))]);
DB::table('provider_access')->insert(['user_id' => $operator->id, 'role' => 'provider_admin', 'created_at' => now(), 'updated_at' => now()]);
$client = Client::create(['legal_name' => 'Fixture browser', 'slug' => $slug, 'status' => 'active']);
$tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Fixture browser', 'slug' => $slug, 'status' => 'active']);
$source = Environment::create(['tenant_id' => $tenant->id, 'kind' => 'production', 'name' => 'Production uji browser', 'slug' => 'production', 'status' => 'provisioning', 'outbound_allowed' => true]);
if (Artisan::call('environment:provision', ['environment' => $source->id]) !== 0) throw new RuntimeException(Artisan::output());
$source->refresh();
$config = config('database.connections.'.config('database.default'));
$config['database'] = $source->database_name;
$config['url'] = null;
config(['database.connections.browser_source' => $config]);
$db = DB::connection('browser_source');
$db->table('clients')->insert(['id' => $client->id, 'legal_name' => $client->legal_name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
$db->table('tenants')->insert(['id' => $tenant->id, 'client_id' => $client->id, 'name' => $tenant->name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
$db->table('environments')->insert(['id' => $source->id, 'tenant_id' => $tenant->id, 'kind' => 'production', 'name' => 'Production uji browser', 'slug' => 'production', 'status' => 'active', 'outbound_allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
file_put_contents(getenv('AUDIT_BROWSER_DIR').'/fixture.json', json_encode(['source_id' => $source->id, 'tenant_id' => $tenant->id, 'email' => $operator->email], JSON_PRETTY_PRINT));
echo "Isolated browser fixture ready.\n";

