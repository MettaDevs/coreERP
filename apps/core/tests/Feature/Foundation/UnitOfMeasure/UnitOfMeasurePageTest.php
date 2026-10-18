<?php

namespace Tests\Feature\Foundation\UnitOfMeasure;

use App\Foundation\UnitOfMeasure\Models\UnitOfMeasure;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class UnitOfMeasurePageTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private string $classId;

    protected function setUp(): void
    {
        parent::setUp();
        $slug = 'uom-'.strtolower(Str::random(8));
        $client = Client::create(['legal_name' => 'Klien uji satuan', 'slug' => $slug, 'status' => 'active']);
        $this->tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Tenant uji satuan', 'slug' => $slug, 'status' => 'active']);
        $this->owner = User::factory()->create();
        $this->makeOwner(TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'status' => 'active']));
        $this->classId = $this->reference('uom_classes', 'CLASS', 'Kelas uji');
        $this->actingAs($this->owner);
    }

    public function test_page_does_not_embed_entire_catalogs(): void
    {
        $this->withoutVite();
        $this->unit('FIRST');
        $this->get('/settings/units-of-measure')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('foundation/unit-of-measure/units-of-measure')
            ->where('canManage', true)
            ->missing('units')->missing('classes')->missing('systems')->missing('conversions'));
    }

    public function test_every_catalog_walks_bounded_batches_without_duplicates(): void
    {
        $source = $this->unit('SOURCE');
        for ($index = 0; $index < 5; $index++) {
            $this->reference('uom_classes', 'C'.$index, 'Kelas '.$index);
            $this->reference('uom_systems', 'S'.$index, 'Sistem '.$index);
            $target = $this->unit('U'.$index);
            DB::table('uom_conversions')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id,
                'from_unit_id' => $source->id, 'to_unit_id' => $target->id,
                'factor' => $index + 1, 'offset' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (['units' => 6, 'classes' => 6, 'systems' => 5, 'conversions' => 5] as $resource => $count) {
            $ids = [];
            $cursor = null;
            do {
                $response = $this->getJson('/api/v1/units-of-measure?'.http_build_query([
                    'incremental' => 1, 'resource' => $resource, 'per_page' => 2, 'cursor' => $cursor,
                ]))->assertOk();
                $rows = $response->json('data');
                $this->assertLessThanOrEqual(2, count($rows));
                $ids = [...$ids, ...array_column($rows, 'id')];
                $this->assertLessThanOrEqual($count, count($ids));
                $cursor = $response->json('next_cursor');
            } while ($cursor !== null);
            $this->assertCount($count, $ids, $resource);
            $this->assertCount($count, array_unique($ids), $resource);
        }
    }

    public function test_search_record_lookup_and_joined_labels_keep_tenant_scope(): void
    {
        $this->unit('A');
        $last = $this->unit('Z');
        $foreignClass = $this->reference('uom_classes', 'FOREIGN', 'Kelas lain', (string) Str::ulid());
        $foreignTenant = (string) DB::table('uom_classes')->where('id', $foreignClass)->value('tenant_id');
        $foreign = UnitOfMeasure::create([
            'tenant_id' => $foreignTenant, 'uom_class_id' => $foreignClass,
            'code' => 'Z', 'name' => 'Satuan tenant lain', 'decimal_places' => 0, 'active' => true,
        ]);
        $this->getJson('/api/v1/units-of-measure?resource=units&per_page=1&q=Z')->assertOk()
            ->assertJsonPath('data.0.id', $last->id)
            ->assertJsonPath('data.0.class_name', 'Kelas uji')
            ->assertJsonPath('next_cursor', null);
        $this->getJson('/api/v1/units-of-measure?resource=units&record_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/units-of-measure?resource=classes&record_id='.$foreignClass)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unit_picker_filters_class_and_excludes_inactive_and_archived_units(): void
    {
        $active = $this->unit('ACTIVE');
        $this->unit('INACTIVE', ['active' => false]);
        $this->unit('ARCHIVED')->delete();
        $otherClass = $this->reference('uom_classes', 'OTHER', 'Kelas lain');
        $this->unit('OTHER', ['uom_class_id' => $otherClass]);
        $this->getJson('/api/v1/units-of-measure?resource=units&active=1&uom_class_id='.$this->classId)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
    }

    public function test_create_returns_full_active_records_and_edit_keeps_version_rules(): void
    {
        $this->postJson('/api/v1/units-of-measure/classes', ['code' => 'NEW', 'name' => 'Kelas baru'])->assertCreated()
            ->assertJsonPath('data.code', 'NEW')->assertJsonPath('data.name', 'Kelas baru')->assertJsonPath('data.active', true);
        $this->postJson('/api/v1/units-of-measure/systems', ['code' => 'NEW', 'name' => 'Sistem baru'])->assertCreated()
            ->assertJsonPath('data.active', true)->assertJsonStructure(['data' => ['id', 'code', 'name']]);
        $created = $this->postJson('/api/v1/units-of-measure', [
            'code' => 'NEW', 'name' => 'Satuan baru', 'decimal_places' => 2, 'uom_class_id' => $this->classId,
        ])->assertCreated()->assertJsonPath('data.active', true)->assertJsonPath('data.class_name', 'Kelas uji')
            ->assertJsonPath('data.uom_system_id', null)->json('data');
        $this->patchJson('/api/v1/units-of-measure/'.$created['id'], ['name' => 'Nama diubah', 'version' => $created['version']])
            ->assertCreated()->assertJsonPath('data.id', $created['id'])->assertJsonPath('data.name', 'Nama diubah');
        $this->patchJson('/api/v1/units-of-measure/'.$created['id'], ['name' => 'Versi basi', 'version' => $created['version']])->assertStatus(409);
        $this->patchJson('/api/v1/units-of-measure/'.$created['id'], ['name' => 'Tanpa versi'])->assertStatus(428);
        $this->assertDatabaseHas('units_of_measure', ['id' => $created['id'], 'name' => 'Nama diubah']);
    }

    public function test_duplicate_codes_return_field_errors(): void
    {
        $this->postJson('/api/v1/units-of-measure/classes', ['code' => 'CLASS', 'name' => 'Duplikat'])->assertUnprocessable()->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', 'Kode ini sudah digunakan. Pilih kode lain.');
        $this->unit('DUPLICATE');
        $this->postJson('/api/v1/units-of-measure', [
            'code' => 'DUPLICATE', 'name' => 'Duplikat', 'decimal_places' => 2, 'uom_class_id' => $this->classId,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_validation_messages_use_field_names_in_indonesian(): void
    {
        $this->postJson('/api/v1/units-of-measure/classes', ['code' => 'tidak valid', 'name' => ''])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Kode hanya boleh memakai huruf, angka, titik, garis bawah, dan tanda hubung.')
            ->assertJsonPath('errors.name.0', 'Nama wajib diisi.');
        $this->getJson('/api/v1/units-of-measure?resource=units&q='.str_repeat('a', 201))->assertUnprocessable()
            ->assertJsonPath('errors.q.0', 'Pencarian tidak boleh lebih dari 200 karakter.');
    }

    public function test_conversion_rejects_unusable_units_and_keeps_identity_on_resave(): void
    {
        $source = $this->unit('SOURCE');
        $target = $this->unit('TARGET');
        $payload = ['from_unit_id' => $source->id, 'to_unit_id' => $target->id, 'factor' => '1000'];
        $created = $this->postJson('/api/v1/units-of-measure/conversions', $payload)->assertCreated()
            ->assertJsonPath('data.from_name', $source->name)->assertJsonPath('data.to_name', $target->name)->json('data');
        $createdAt = DB::table('uom_conversions')->where('id', $created['id'])->value('created_at');
        $this->postJson('/api/v1/units-of-measure/conversions', [...$payload, 'factor' => '2000'])->assertCreated()->assertJsonPath('data.id', $created['id']);
        $this->assertSame($createdAt, DB::table('uom_conversions')->where('id', $created['id'])->value('created_at'));
        $this->getJson('/api/v1/units-of-measure?resource=conversions&from_unit_id='.$source->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.from_name', $source->name)->assertJsonPath('data.0.to_name', $target->name);
        $target->update(['active' => false]);
        $this->postJson('/api/v1/units-of-measure/conversions', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/units-of-measure/conversions', [...$payload, 'to_unit_id' => $source->id])->assertUnprocessable();
        $otherClass = $this->reference('uom_classes', 'OTHER', 'Kelas lain');
        $wrongClass = $this->unit('WRONG-CLASS', ['uom_class_id' => $otherClass]);
        $this->postJson('/api/v1/units-of-measure/conversions', [...$payload, 'to_unit_id' => $wrongClass->id])->assertUnprocessable();
        $archived = $this->unit('ARCHIVED');
        $archived->delete();
        $this->postJson('/api/v1/units-of-measure/conversions', [...$payload, 'to_unit_id' => $archived->id])->assertUnprocessable();
    }

    private function reference(string $table, string $code, string $name, ?string $tenant = null): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert(['id' => $id, 'tenant_id' => $tenant ?? $this->tenant->id, 'code' => $code, 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function unit(string $code, array $attributes = []): UnitOfMeasure
    {
        return UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id, 'uom_class_id' => $this->classId,
            'code' => $code, 'name' => 'Satuan '.$code, 'decimal_places' => 2, 'active' => true, ...$attributes,
        ]);
    }
}
