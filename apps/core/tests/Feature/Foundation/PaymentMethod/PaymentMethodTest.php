<?php

namespace Tests\Feature\Foundation\PaymentMethod;

use App\Foundation\PaymentMethod\Models\PaymentMethod;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\PaymentMethodDirectory;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $owner = User::factory()->create();
        $this->makeOwner(TenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $owner->id,
            'status' => 'active',
        ]));
        $this->actingAs($owner);
    }

    public function test_creation_is_active_and_replay_checks_the_original_payload(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'code' => 'TRANSFER',
            'name' => 'Transfer Bank',
            'description' => 'Pembayaran via transfer antar bank',
        ];

        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payment-methods', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'TRANSFER')
            ->assertJsonPath('data.name', 'Transfer Bank')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.description', $payload['description'])
            ->json('data');

        $this->postJson('/api/v1/payment-methods', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $created['id']);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payment-methods', [...$payload, 'name' => 'Nama Berbeda'])
            ->assertConflict();

        $this->assertDatabaseCount('payment_methods', 1);
        $this->assertDatabaseHas('payment_methods', [
            'id' => $created['id'],
            'code' => 'TRANSFER',
        ]);
    }

    public function test_duplicate_code_is_rejected_with_422(): void
    {
        $this->createPaymentMethod('TUNAI', 'Kas / Tunai');

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/payment-methods', [
                'code' => 'TUNAI',
                'name' => 'Tunai Lainnya',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_updates_require_current_version_and_deletion_is_soft(): void
    {
        $method = $this->createPaymentMethod('GIRO', 'Bilyet Giro');
        $url = '/api/v1/payment-methods/'.$method->id;

        $this->patchJson($url, [
            'code' => 'GIRO',
            'name' => 'Bilyet Giro Update',
            'description' => 'Deskripsi baru',
            'active' => false,
            'version' => $method->version,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Bilyet Giro Update')
            ->assertJsonPath('data.active', false);

        // Stale version rejected with 409
        $this->patchJson($url, [
            'code' => 'GIRO',
            'name' => 'Nama Basi',
            'active' => true,
            'version' => 1,
        ])->assertConflict();

        $fresh = $method->refresh();
        $this->deleteJson($url, [], ['If-Match' => 'W/"'.$fresh->version.'"'])
            ->assertNoContent();

        $this->assertSoftDeleted('payment_methods', ['id' => $method->id]);

        // Creating the same code again succeeds after soft delete due to partial unique index
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/payment-methods', [
                'code' => 'GIRO',
                'name' => 'Giro Baru Setelah Arsip',
            ])->assertCreated();
    }

    public function test_search_and_cursor_walk_are_bounded_and_tenant_scoped(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createPaymentMethod('METODE-'.$i, 'Metode '.$i);
        }

        $foreignTenant = $this->createTenant();
        $foreign = PaymentMethod::query()->create([
            'tenant_id' => $foreignTenant->id,
            'code' => 'FOREIGN',
            'name' => 'Metode Asing',
            'active' => true,
        ]);

        $this->getJson('/api/v1/payment-methods?q=FOREIGN')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payment-methods?q=METODE-3&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'METODE-3');

        $seen = [];
        $cursor = null;
        do {
            $response = $this->getJson('/api/v1/payment-methods?'.http_build_query(['per_page' => 2, 'cursor' => $cursor]))->assertOk();
            $this->assertLessThanOrEqual(2, count($response->json('data')));
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('next_cursor');
            $this->assertLessThanOrEqual(5, count($seen));
        } while ($cursor !== null);

        $this->assertCount(5, array_unique($seen));
        $this->patchJson('/api/v1/payment-methods/'.$foreign->id, [
            'code' => 'HACK',
            'name' => 'Tidak Boleh',
            'active' => true,
            'version' => 1,
        ])->assertNotFound();
    }

    public function test_directory_contract_lookup_and_resolve(): void
    {
        $method = $this->createPaymentMethod('DIR-1', 'Direktori 1');
        $directory = app(PaymentMethodDirectory::class);

        $lookup = $directory->lookup($this->tenant->id, ['q' => 'DIR-1']);
        $this->assertCount(1, $lookup['data']);
        $this->assertSame('DIR-1', $lookup['data'][0]->code);

        $resolve = $directory->resolve($this->tenant->id, [$method->id]);
        $this->assertArrayHasKey($method->id, $resolve);
        $this->assertSame('Direktori 1', $resolve[$method->id]['name']);

        $active = $directory->active($this->tenant->id);
        $this->assertNotEmpty($active);
        $this->assertSame('DIR-1', $active[0]['code']);
    }

    private function createPaymentMethod(string $code, string $name): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'name' => $name,
            'active' => true,
        ])->refresh();
    }

    private function createTenant(): Tenant
    {
        $slug = 'payment-'.Str::lower(Str::random(10));
        $client = Client::create(['legal_name' => 'Klien uji', 'slug' => $slug, 'status' => 'active']);

        return Tenant::create(['client_id' => $client->id, 'name' => 'Tenant uji', 'slug' => $slug, 'status' => 'active']);
    }
}
