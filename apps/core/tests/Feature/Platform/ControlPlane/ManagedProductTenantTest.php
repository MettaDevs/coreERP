<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\ControlPlane\Models\ManagedProductTenant;
use App\Platform\ControlPlane\Products\ProductTenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManagedProductTenantTest extends TestCase
{
    use RefreshDatabase;

    private function input(): array
    {
        $id = DB::table('users')->insertGetId(['name' => 'Operator', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('provider_access')->insert(['user_id' => $id, 'role' => 'provider_admin', 'created_at' => now(), 'updated_at' => now()]);
        config(['coreerp.control_plane_token' => 'test-token', 'managed_products.products.faskes.worker_url' => 'http://worker.test', 'managed_products.products.faskes.worker_token' => 'worker-secret']);

        return ['product' => 'faskes', 'profile' => 'apotek', 'name' => 'Apotek Uji', 'slug' => 'apotek-uji', 'owner_name' => 'Pemilik', 'owner_email' => 'owner@example.test', 'idempotency_key' => (string) Str::uuid(), 'requested_by' => $id];
    }

    public function test_creation_is_idempotent_and_ready_credentials_are_not_in_lists(): void
    {
        $input = $this->input();
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;
            $this->assertSame('Bearer worker-secret', $request->header('Authorization')[0]);
            if ($request->method() === 'PUT') {
                $this->assertSame('Apotek Uji', $request['name']);

                return Http::response(['status' => 'provisioning'], 202);
            }

            return Http::response(['status' => 'ready', 'public_url' => 'https://apotek-uji.demo.faskes.mettamedic.id/', 'credentials' => ['username' => 'owner', 'password' => 'owner-secret', 'operator_username' => 'metta', 'operator_password' => 'operator-secret']]);
        });
        $reply = $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted();
        $id = $reply->json('id');
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('managed_product_tenants', 1);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('users', 1);
        app(ProductTenantProvisioner::class)->reconcile($id);
        $this->withToken('test-token')->getJson('/api/internal/v1/product-tenants/'.$id)->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('credentials.password', 'owner-secret');
        $list = $this->withToken('test-token')->getJson('/api/internal/v1/product-tenants')->assertOk();
        $this->assertStringNotContainsString('owner-secret', $list->getContent());
        $this->assertStringNotContainsString('operator-secret', DB::table('console_settings')->value('value'));
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants/'.$id.'/retry', ['requested_by' => $input['requested_by']])->assertConflict();
        $this->assertSame(2, $requests);
    }

    public function test_changed_payload_with_same_key_and_duplicate_slug_are_rejected(): void
    {
        $input = $this->input();
        Http::fake(['*' => Http::response(['status' => 'provisioning'], 202)]);
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted();
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', array_merge($input, ['name' => 'Berbeda']))->assertConflict();
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', array_merge($input, ['idempotency_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseCount('managed_product_tenants', 1);
    }

    public function test_profile_and_operator_guards_reject_unavailable_access(): void
    {
        $input = $this->input();
        Http::preventStrayRequests();
        $this->postJson('/api/internal/v1/product-tenants', $input)->assertUnauthorized();
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', array_merge($input, ['profile' => 'puskesmas']))->assertUnprocessable();
        DB::table('provider_access')->where('user_id', $input['requested_by'])->delete();
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertNotFound();
        $this->assertDatabaseCount('managed_product_tenants', 0);
    }

    public function test_worker_failure_can_retry_without_changing_release(): void
    {
        $input = $this->input();
        $originalVersion = config('managed_products.products.faskes.release.version');
        Http::fake(function ($request) use ($originalVersion) {
            if (! $request['retry']) {
                return Http::response(['status' => 'failed', 'failure_message' => 'secret detail']);
            }
            $this->assertSame($originalVersion, $request['release']['version']);

            return Http::response(['status' => 'provisioning']);
        });
        $id = $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted()->json('id');
        $this->assertDatabaseHas('managed_product_tenants', ['id' => $id, 'status' => 'failed']);
        config(['managed_products.products.faskes.release.version' => 'newer']);
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants/'.$id.'/retry', ['requested_by' => $input['requested_by']])->assertAccepted()->assertJsonPath('status', 'provisioning');
        $this->assertSame(1, ManagedProductTenant::query()->count());
    }

    public function test_worker_cannot_publish_another_host_or_mark_ready_without_credentials(): void
    {
        $input = $this->input();
        $url = 'https://other.example.test/';
        Http::fake(function () use (&$url) {
            return Http::response(['status' => 'ready', 'public_url' => $url]);
        });
        $reply = $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted();
        $reply->assertJsonPath('status', 'queued')->assertJsonPath('public_url', null);
        $tenant = ManagedProductTenant::query()->findOrFail($reply->json('id'));
        $url = 'https://apotek-uji.demo.faskes.mettamedic.id/';
        app(ProductTenantProvisioner::class)->reconcile($tenant->id);
        $this->assertSame('queued', $tenant->fresh()->status);
        $tenant->update(['lease_until' => now()->addMinute()]);
        app(ProductTenantProvisioner::class)->reconcile($tenant->id);
        Http::assertSentCount(2);
    }

    public function test_worker_request_rejection_becomes_failed_without_exposing_its_body(): void
    {
        $input = $this->input();
        Http::fake(['*' => Http::response(['message' => 'private worker detail'], 409)]);
        $reply = $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted();
        $reply->assertJsonPath('status', 'failed');
        $this->assertStringNotContainsString('private worker detail', $reply->getContent());
    }

    public function test_expiry_dates_are_rejected_until_the_lifecycle_is_available(): void
    {
        $input = $this->input();
        $input['expires_at'] = now()->addDay()->toIso8601String();
        $this->withToken('test-token')->postJson('/api/internal/v1/product-tenants', $input)
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $this->assertDatabaseCount('managed_product_tenants', 0);
    }
}
