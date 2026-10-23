<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\Environment;
use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class CreateEnvironmentTest extends TestCase
{
    use CoreSchema;

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

    private function ordinaryUser(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Bukan Operator',
            'email' => Str::lower(Str::random(8)).'@contoh.test',
            'password' => bcrypt('rahasia'),
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

    public function test_operator_route_sends_command_and_reads_the_scoped_result(): void
    {
        config(['core.base_url' => 'http://core.test', 'core.token' => 'creation-test']);
        $tenant = $this->tenant();
        $operator = $this->operator();
        $environment = Environment::create(['tenant_id' => $tenant, 'kind' => 'production', 'name' => 'Produksi', 'slug' => 'produksi', 'status' => 'provisioning', 'outbound_allowed' => true, 'hosting' => 'client_server']);
        Http::preventStrayRequests();
        Http::fake(['http://core.test/api/internal/v1/environments' => Http::response(['environment_id' => $environment->id, 'tenant_id' => $tenant], 201)]);
        $this->actingAs($operator)->post('/lingkungan', ['tenant_id' => $tenant, 'kind' => 'production', 'name' => 'Produksi', 'hosting' => 'client_server'])->assertRedirect('/lingkungan/'.$environment->id);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer creation-test') && $request['requested_by'] === $operator->id && $request['tenant_id'] === $tenant && $request['hosting'] === 'client_server');
        $this->assertSame(1, Environment::count());
        $this->assertDatabaseCount('environment_operations', 0);
    }

    public function test_rejection_or_invalid_success_never_creates_local_rows(): void
    {
        config(['core.base_url' => 'http://core.test']);
        $tenant = $this->tenant();
        $operator = $this->operator();
        foreach ([[422, ['errors' => ['name' => ['Sudah ada.']]]], [202, []], [201, ['environment_id' => 'foreign-id', 'tenant_id' => $tenant]], [201, ['environment_id' => 'id', 'tenant_id' => 'foreign-tenant']]] as [$status, $body]) {
            Http::fake(['http://core.test/api/internal/v1/environments' => Http::response($body, $status)]);
            $this->actingAs($operator)->post('/lingkungan', ['tenant_id' => $tenant, 'kind' => 'production', 'name' => 'Produksi'])->assertSessionHasErrors('name');
        }
        $this->assertSame(0, Environment::count());
        $this->assertDatabaseCount('environment_operations', 0);
    }

    public function test_non_operator_cannot_send_creation_command(): void
    {
        Http::fake();
        $this->actingAs($this->ordinaryUser())->post('/lingkungan', ['tenant_id' => $this->tenant(), 'kind' => 'production', 'name' => 'Produksi'])->assertNotFound();
        Http::assertNothingSent();
    }
}
