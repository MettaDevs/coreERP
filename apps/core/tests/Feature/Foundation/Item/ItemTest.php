<?php

namespace Tests\Feature\Foundation\Item;

use App\Foundation\Item\Models\Item;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\ItemDirectory;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->owner = User::factory()->create();
        $this->makeOwner(TenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'status' => 'active',
        ]));
        $this->actingAs($this->owner);
    }

    public function test_page_renders_with_permission(): void
    {
        $this->get('/settings/service-items')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('foundation/item/service-items')
                ->where('canManage', true)
            );
    }

    public function test_creation_is_active_and_replay_checks_the_original_payload(): void
    {
        $uomId = $this->createUom('JAM', 'Jam Kerja');
        $key = (string) Str::uuid();
        $payload = [
            'code' => 'JSA-001',
            'name' => 'Jasa Pemeliharaan Sistem',
            'type' => 'service',
            'unit_of_measure_id' => $uomId,
            'description' => 'Layanan pemeliharaan teknis sistem mingguan',
        ];

        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/items', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'JSA-001')
            ->assertJsonPath('data.name', 'Jasa Pemeliharaan Sistem')
            ->assertJsonPath('data.type', 'service')
            ->assertJsonPath('data.unit_of_measure_id', $uomId)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.description', $payload['description'])
            ->json('data');

        $this->postJson('/api/v1/items', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $created['id']);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/items', [...$payload, 'name' => 'Nama Berbeda'])
            ->assertConflict();

        $this->assertDatabaseCount('items', 1);
        $this->assertDatabaseHas('items', [
            'id' => $created['id'],
            'code' => 'JSA-001',
        ]);
    }

    public function test_invalid_or_foreign_uom_is_rejected_with_422(): void
    {
        $foreignTenant = $this->createTenant();
        $foreignUomId = $this->createUom('ASING', 'Satuan Asing', $foreignTenant->id);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items', [
                'code' => 'JSA-INV1',
                'name' => 'Jasa UoM Asing',
                'unit_of_measure_id' => $foreignUomId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_of_measure_id']);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items', [
                'code' => 'JSA-INV2',
                'name' => 'Jasa UoM Ngawur',
                'unit_of_measure_id' => (string) Str::ulid(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_of_measure_id']);
    }

    public function test_duplicate_code_is_rejected_with_422(): void
    {
        $uomId = $this->createUom();
        $this->createItem('SRV-DUP', 'Jasa Awal', $uomId);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items', [
                'code' => 'SRV-DUP',
                'name' => 'Jasa Duplikat',
                'unit_of_measure_id' => $uomId,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_updates_require_current_version_and_deletion_is_soft(): void
    {
        $uomId = $this->createUom();
        $item = $this->createItem('SRV-MOD', 'Jasa Konsultasi', $uomId);
        $url = '/api/v1/items/'.$item->id;

        $this->patchJson($url, [
            'code' => 'SRV-MOD',
            'name' => 'Jasa Konsultasi Senior',
            'type' => 'service',
            'unit_of_measure_id' => $uomId,
            'description' => 'Deskripsi baru',
            'active' => false,
            'version' => $item->version,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Jasa Konsultasi Senior')
            ->assertJsonPath('data.active', false);

        // Stale version rejected with 409
        $this->patchJson($url, [
            'code' => 'SRV-MOD',
            'name' => 'Nama Basi',
            'unit_of_measure_id' => $uomId,
            'active' => true,
            'version' => 1,
        ])->assertConflict();

        // Missing version rejected with 428
        $this->patchJson($url, [
            'code' => 'SRV-MOD',
            'name' => 'Tanpa Versi',
            'unit_of_measure_id' => $uomId,
            'active' => true,
        ])->assertStatus(428);

        $fresh = $item->refresh();
        $this->deleteJson($url, [], ['If-Match' => 'W/"'.$fresh->version.'"'])
            ->assertNoContent();

        $this->assertSoftDeleted('items', ['id' => $item->id]);

        // Creating the same code again succeeds after soft delete due to partial unique index
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items', [
                'code' => 'SRV-MOD',
                'name' => 'Jasa Konsultasi Baru Pasca Arsip',
                'unit_of_measure_id' => $uomId,
            ])->assertCreated();
    }

    public function test_update_status_toggles_active_state(): void
    {
        $uomId = $this->createUom();
        $item = $this->createItem('SRV-TOG', 'Jasa Toggle', $uomId);

        $this->patchJson('/api/v1/items/'.$item->id.'/status', [
            'active' => false,
        ], ['If-Match' => 'W/"'.$item->version.'"'])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $fresh = $item->refresh();
        $this->patchJson('/api/v1/items/'.$item->id.'/status', [
            'active' => true,
        ], ['If-Match' => 'W/"'.$fresh->version.'"'])
            ->assertOk()
            ->assertJsonPath('data.active', true);
    }

    public function test_search_filter_and_cursor_walk_are_bounded_and_tenant_isolated(): void
    {
        $uomId = $this->createUom();
        for ($i = 0; $i < 5; $i++) {
            $this->createItem('SRV-'.$i, 'Service '.$i, $uomId, $i % 2 === 0 ? 'service' : 'inventory');
        }

        $foreignTenant = $this->createTenant();
        $foreignUom = $this->createUom('PCS-F', 'Pcs Foreign', $foreignTenant->id);
        $foreign = Item::query()->create([
            'tenant_id' => $foreignTenant->id,
            'code' => 'FOREIGN',
            'name' => 'Item Asing',
            'type' => 'service',
            'unit_of_measure_id' => $foreignUom,
            'active' => true,
        ]);

        $this->getJson('/api/v1/items?q=FOREIGN')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/items?q=SRV-3&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'SRV-3');

        // Filter by type
        $this->getJson('/api/v1/items?type=inventory')->assertOk()->assertJsonCount(2, 'data');

        // Incremental cursor walk
        $seen = [];
        $cursor = null;
        do {
            $response = $this->getJson('/api/v1/items?'.http_build_query(['per_page' => 2, 'cursor' => $cursor]))->assertOk();
            $this->assertLessThanOrEqual(2, count($response->json('data')));
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('next_cursor');
            $this->assertLessThanOrEqual(5, count($seen));
        } while ($cursor !== null);

        $this->assertCount(5, array_unique($seen));

        // Foreign record access returns 404
        $this->getJson('/api/v1/items/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/v1/items/'.$foreign->id, [
            'code' => 'HACK',
            'name' => 'Tidak Boleh',
            'unit_of_measure_id' => $uomId,
            'active' => true,
            'version' => 1,
        ])->assertNotFound();
    }

    public function test_directory_contract_lookup_resolve_and_active(): void
    {
        $uomId = $this->createUom('PAK', 'Paket');
        $item = $this->createItem('DIR-ITEM', 'Item Direktori', $uomId);
        $directory = app(ItemDirectory::class);

        $lookup = $directory->lookup($this->tenant->id, ['q' => 'DIR-ITEM']);
        $this->assertCount(1, $lookup['data']);
        $this->assertSame('DIR-ITEM', $lookup['data'][0]->code);

        $resolve = $directory->resolve($this->tenant->id, [$item->id]);
        $this->assertArrayHasKey($item->id, $resolve);
        $this->assertSame('Item Direktori', $resolve[$item->id]['name']);
        $this->assertSame('service', $resolve[$item->id]['type']);
        $this->assertSame($uomId, $resolve[$item->id]['unit_of_measure_id']);

        $active = $directory->active($this->tenant->id);
        $this->assertNotEmpty($active);
        $this->assertSame('DIR-ITEM', $active[0]['code']);
    }

    private function createUom(string $code = 'PCS', string $name = 'Pieces', ?string $tenantId = null): string
    {
        $tenantId = $tenantId ?? $this->tenant->id;
        $classId = (string) Str::ulid();
        $now = now();
        DB::table('uom_classes')->insert([
            'id' => $classId,
            'tenant_id' => $tenantId,
            'code' => 'CLASS-'.Str::upper(Str::random(6)),
            'name' => 'Class',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $uomId = (string) Str::ulid();
        DB::table('units_of_measure')->insert([
            'id' => $uomId,
            'tenant_id' => $tenantId,
            'uom_class_id' => $classId,
            'code' => $code,
            'name' => $name,
            'symbol' => $code,
            'decimal_places' => 0,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uomId;
    }

    private function createItem(string $code, string $name, string $uomId, string $type = 'service'): Item
    {
        return Item::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'unit_of_measure_id' => $uomId,
            'active' => true,
        ])->refresh();
    }

    private function createTenant(): Tenant
    {
        $slug = 'item-'.Str::lower(Str::random(10));
        $client = Client::create(['legal_name' => 'Klien uji', 'slug' => $slug, 'status' => 'active']);

        return Tenant::create(['client_id' => $client->id, 'name' => 'Tenant uji', 'slug' => $slug, 'status' => 'active']);
    }
}
