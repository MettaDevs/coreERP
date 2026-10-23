<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\Environment\Models\Environment;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnvironmentCreationApiTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Operator Uji',
            'email' => 'operator@contoh.test',
            'password' => bcrypt('rahasia'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('provider_access')->insert([
            'user_id' => $id,
            'role' => 'provider_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function tenant(string $name = 'PT Contoh'): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    public function test_creation_persists_environment_operation_and_operator_audit(): void
    {
        config(['coreerp.control_plane_token' => 'creation-test']);
        $operator = $this->operator();
        $tenant = $this->tenant();
        $fields = ['tenant_id' => $tenant, 'kind' => 'demo', 'name' => 'Peragaan', 'expires_at' => now()->addWeek()->toDateString(), 'requested_by' => $operator->id];
        $this->postJson('/api/internal/v1/environments', $fields)->assertUnauthorized();
        $response = $this->withToken('creation-test')->postJson('/api/internal/v1/environments', $fields)->assertCreated()->assertJsonPath('tenant_id', $tenant);
        $environment = Environment::findOrFail($response->json('environment_id'));
        $this->assertSame('provisioning', $environment->status);
        $this->assertFalse($environment->outbound_allowed);
        $this->assertSame('succeeded', $environment->operations()->sole()->status);
        $this->assertDatabaseHas('operator_audit_events', ['subject_id' => $environment->id, 'user_id' => $operator->id, 'action' => 'environment.created']);
        $this->withToken('creation-test')->postJson('/api/internal/v1/environments', $fields)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(1, Environment::where('tenant_id', $tenant)->count());
        $this->assertSame(1, DB::table('operator_audit_events')->where('subject_id', $environment->id)->count());
    }

    public function test_validation_hosting_and_slug_retry_remain_owned_by_core(): void
    {
        config(['coreerp.control_plane_token' => 'creation-test']);
        $fields = ['tenant_id' => $this->tenant(), 'name' => 'Nama Sama', 'requested_by' => $this->operator()->id];
        $this->withToken('creation-test')->postJson('/api/internal/v1/environments', $fields + ['kind' => 'demo'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $this->withToken('creation-test')->postJson('/api/internal/v1/environments', $fields + ['kind' => 'sandbox', 'hosting' => 'client_server'])->assertUnprocessable()->assertJsonValidationErrors('hosting');
        $this->withToken('creation-test')->postJson('/api/internal/v1/environments', $fields + ['kind' => 'sandbox'])->assertCreated();
        $this->withToken('creation-test')->postJson('/api/internal/v1/environments', $fields + ['kind' => 'production', 'hosting' => 'client_server'])->assertCreated();
        $production = Environment::where('tenant_id', $fields['tenant_id'])->where('kind', 'production')->sole();
        $this->assertSame('nama-sama-2', $production->slug);
        $this->assertTrue($production->outbound_allowed);
        $this->assertSame('client_server', $production->hosting);
        $this->withToken('creation-test')->postJson('/api/internal/v1/environments', $fields + ['kind' => 'production'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
