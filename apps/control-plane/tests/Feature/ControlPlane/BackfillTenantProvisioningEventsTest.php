<?php

namespace Tests\Feature\ControlPlane;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillTenantProvisioningEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_idempotent_and_can_be_limited_to_tenants(): void
    {
        $client = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $client,
            'legal_name' => 'Backfill client',
            'slug' => 'backfill-client',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tenant = (string) Str::ulid();
        $otherTenant = (string) Str::ulid();
        DB::table('tenants')->insert([
            ['id' => $tenant, 'client_id' => $client, 'name' => 'Backfill tenant', 'slug' => 'backfill-tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $otherTenant, 'client_id' => $client, 'name' => 'Other tenant', 'slug' => 'other-tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('tenant-provisioning:backfill', ['--tenant' => [$tenant]])
            ->assertExitCode(0);
        $this->artisan('tenant-provisioning:backfill', ['--tenant' => [$tenant]])
            ->assertExitCode(0);

        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => $tenant,
            'type' => 'core.tenant.provisioned.v1',
        ]);
        $this->assertSame(['app_ids' => []], json_decode((string) DB::table('outbox_events')->value('payload'), true));
        $this->assertDatabaseMissing('outbox_events', ['tenant_id' => $otherTenant]);
    }
}
