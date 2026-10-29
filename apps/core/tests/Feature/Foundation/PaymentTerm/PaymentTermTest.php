<?php

namespace Tests\Feature\Foundation\PaymentTerm;

use App\Foundation\PaymentTerm\Models\PaymentTerm;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\PaymentTermDirectory;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class PaymentTermTest extends TestCase
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
            'code' => 'NET30',
            'name' => 'Net 30 Hari',
            'due_days' => 30,
            'description' => 'Pembayaran penuh 30 hari kalender setelah faktur diterima',
        ];

        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payment-terms', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'NET30')
            ->assertJsonPath('data.name', 'Net 30 Hari')
            ->assertJsonPath('data.due_days', 30)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.description', $payload['description'])
            ->json('data');

        $this->postJson('/api/v1/payment-terms', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $created['id']);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payment-terms', [...$payload, 'name' => 'Nama Berbeda'])
            ->assertConflict();

        $this->assertDatabaseCount('payment_terms', 1);
        $this->assertDatabaseHas('payment_terms', [
            'id' => $created['id'],
            'code' => 'NET30',
        ]);
    }

    public function test_duplicate_code_is_rejected_with_422(): void
    {
        $this->createPaymentTerm('COD', 'Cash On Delivery', 0);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/payment-terms', [
                'code' => 'COD',
                'name' => 'Bayar di Tempat',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_updates_require_current_version_and_deletion_is_soft(): void
    {
        $term = $this->createPaymentTerm('NET14', 'Net 14 Hari', 14);
        $url = '/api/v1/payment-terms/'.$term->id;

        $this->patchJson($url, [
            'code' => 'NET14',
            'name' => 'Net Dua Pekan',
            'due_days' => 14,
            'description' => 'Deskripsi baru',
            'active' => false,
            'version' => $term->version,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Net Dua Pekan')
            ->assertJsonPath('data.active', false);

        // Stale version rejected with 409
        $this->patchJson($url, [
            'code' => 'NET14',
            'name' => 'Nama Basi',
            'active' => true,
            'version' => 1,
        ])->assertConflict();

        $fresh = $term->refresh();
        $this->deleteJson($url, [], ['If-Match' => 'W/"'.$fresh->version.'"'])
            ->assertNoContent();

        $this->assertSoftDeleted('payment_terms', ['id' => $term->id]);

        // Creating the same code again succeeds after soft delete due to partial unique index
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/payment-terms', [
                'code' => 'NET14',
                'name' => 'Net 14 Baru Setelah Arsip',
            ])->assertCreated();
    }

    public function test_search_and_cursor_walk_are_bounded_and_tenant_scoped(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createPaymentTerm('TERM-'.$i, 'Term '.$i, $i * 10);
        }

        $foreignTenant = $this->createTenant();
        $foreign = PaymentTerm::query()->create([
            'tenant_id' => $foreignTenant->id,
            'code' => 'FOREIGN',
            'name' => 'Term Asing',
            'due_days' => 0,
            'active' => true,
        ]);

        $this->getJson('/api/v1/payment-terms?q=FOREIGN')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payment-terms?q=TERM-3&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'TERM-3');

        $seen = [];
        $cursor = null;
        do {
            $response = $this->getJson('/api/v1/payment-terms?'.http_build_query(['per_page' => 2, 'cursor' => $cursor]))->assertOk();
            $this->assertLessThanOrEqual(2, count($response->json('data')));
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('next_cursor');
            $this->assertLessThanOrEqual(5, count($seen));
        } while ($cursor !== null);

        $this->assertCount(5, array_unique($seen));
        $this->patchJson('/api/v1/payment-terms/'.$foreign->id, [
            'code' => 'HACK',
            'name' => 'Tidak Boleh',
            'active' => true,
            'version' => 1,
        ])->assertNotFound();
    }

    public function test_directory_contract_lookup_and_resolve(): void
    {
        $term = $this->createPaymentTerm('DIR-1', 'Direktori 1', 7);
        $directory = app(PaymentTermDirectory::class);

        $lookup = $directory->lookup($this->tenant->id, ['q' => 'DIR-1']);
        $this->assertCount(1, $lookup['data']);
        $this->assertSame('DIR-1', $lookup['data'][0]->code);

        $resolve = $directory->resolve($this->tenant->id, [$term->id]);
        $this->assertArrayHasKey($term->id, $resolve);
        $this->assertSame('Direktori 1', $resolve[$term->id]['name']);
        $this->assertSame(7, $resolve[$term->id]['due_days']);

        $active = $directory->active($this->tenant->id);
        $this->assertNotEmpty($active);
        $this->assertSame('DIR-1', $active[0]['code']);
        $this->assertSame(7, $active[0]['due_days']);
    }

    private function createPaymentTerm(string $code, string $name, int $dueDays = 0): PaymentTerm
    {
        return PaymentTerm::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'name' => $name,
            'due_days' => $dueDays,
            'active' => true,
        ])->refresh();
    }

    private function createTenant(): Tenant
    {
        $slug = 'term-'.Str::lower(Str::random(10));
        $client = Client::create(['legal_name' => 'Klien uji', 'slug' => $slug, 'status' => 'active']);

        return Tenant::create(['client_id' => $client->id, 'name' => 'Tenant uji', 'slug' => $slug, 'status' => 'active']);
    }
}
