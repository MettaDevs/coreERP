<?php

namespace Tests\Feature\Foundation\FundingSource;

use App\Foundation\FundingSource\Models\FundingSource;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\FundingSourceDirectory;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class FundingSourceTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $owner = User::factory()->create();
        $this->makeOwner(TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $owner->id, 'status' => 'active']));
        $this->actingAs($owner);
    }

    public function test_creation_is_active_and_replay_checks_the_original_payload(): void
    {
        $key = (string) Str::uuid();
        $payload = ['name' => 'Sumber uji', 'description' => 'Pembiayaan kebutuhan unit'];
        $created = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/funding-sources', $payload)
            ->assertCreated()->assertJsonPath('data.active', true)->assertJsonPath('data.description', $payload['description'])->assertJsonMissingPath('data.code')->json('data');
        $this->postJson('/api/v1/funding-sources', $payload)->assertOk()->assertJsonPath('data.id', $created['id']);
        $this->postJson('/api/v1/funding-sources', [...$payload, 'name' => 'Isian berbeda'])->assertConflict();
        $this->postJson('/api/v1/funding-sources', [...$payload, 'description' => 'Deskripsi berbeda'])->assertConflict();
        $this->assertDatabaseCount('funding_sources', 1);
        $this->assertDatabaseHas('funding_sources', ['id' => $created['id'], 'code' => null]);
    }

    public function test_updates_require_current_version_and_deletion_is_soft(): void
    {
        $source = $this->source('EDIT');
        $url = '/api/v1/funding-sources/'.$source->id;
        $this->patchJson($url, ['name' => 'Nama diperbarui', 'description' => 'Deskripsi baru', 'active' => false, 'version' => 1])->assertOk()
            ->assertJsonPath('data.name', 'Nama diperbarui')->assertJsonPath('data.description', 'Deskripsi baru')->assertJsonPath('data.active', false)->assertJsonMissingPath('data.code');
        $this->assertDatabaseHas('funding_sources', ['id' => $source->id, 'code' => 'EDIT']);
        $this->patchJson($url, ['name' => 'Nama basi', 'active' => true, 'version' => 1])->assertConflict();
        $version = $source->refresh()->version;
        $this->deleteJson($url, ['version' => $version])->assertNoContent();
        $this->assertSoftDeleted('funding_sources', ['id' => $source->id]);
    }

    public function test_name_and_description_are_selected_by_identity_without_a_business_code(): void
    {
        $first = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/funding-sources', ['name' => 'Sumber pembiayaan'])
            ->assertCreated()->assertJsonPath('data.description', null)->json('data');
        $second = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/funding-sources', ['name' => 'Sumber pembiayaan', 'description' => 'Khusus peralatan'])
            ->assertCreated()->json('data');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseHas('funding_sources', ['id' => $first['id'], 'code' => null]);
        $this->assertDatabaseHas('funding_sources', ['id' => $second['id'], 'code' => null]);
        $this->getJson('/api/v1/funding-sources?q=peralatan')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second['id']);
    }

    public function test_search_and_cursor_walk_are_bounded_and_tenant_scoped(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->source('CODE-'.$index);
        }
        $foreign = $this->source('FOREIGN', $this->createTenant()->id);
        $this->getJson('/api/v1/funding-sources?record_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/funding-sources?q=CODE-4&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'CODE-4')->assertJsonMissingPath('data.0.code');
        $descriptionSource = FundingSource::query()->where('tenant_id', $this->tenant->id)->where('name', 'CODE-3')->firstOrFail();
        $descriptionSource->update(['description' => 'Hibah peralatan khusus']);
        $this->getJson('/api/v1/funding-sources?q=peralatan&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $descriptionSource->id);
        $seen = [];
        $cursor = null;
        do {
            $response = $this->getJson('/api/v1/funding-sources?'.http_build_query(['per_page' => 2, 'cursor' => $cursor]))->assertOk();
            $this->assertLessThanOrEqual(2, count($response->json('data')));
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('next_cursor');
            $this->assertLessThanOrEqual(5, count($seen));
        } while ($cursor !== null);
        $this->assertCount(5, array_unique($seen));
        $this->patchJson('/api/v1/funding-sources/'.$foreign->id, ['name' => 'Tidak boleh', 'active' => true, 'version' => 1])->assertNotFound();
    }

    public function test_lookup_keeps_historical_labels_but_excludes_unusable_new_choices(): void
    {
        $active = $this->source('ACTIVE');
        $inactive = $this->source('INACTIVE');
        $inactive->update(['active' => false]);
        $archived = $this->source('ARCHIVED');
        $archived->delete();
        $directory = app(FundingSourceDirectory::class);
        $batch = $directory->lookup($this->tenant->id, []);
        $this->assertSame([$active->id], $batch['data']->pluck('id')->all());
        $historical = $directory->lookup($this->tenant->id, ['record_ids' => [$inactive->id, $archived->id]]);
        $this->assertCount(2, $historical['data']);
        $resolved = $directory->resolve($this->tenant->id, [$inactive->id, $archived->id]);
        $this->assertFalse($resolved[$inactive->id]['active']);
        $this->assertArrayNotHasKey($archived->id, $resolved);
    }

    public function test_legacy_import_preserves_identity_and_separates_tenants(): void
    {
        $directory = app(FundingSourceDirectory::class);
        $first = $directory->importLegacy($this->tenant->id, ' Sumber lama ');
        $this->assertSame($first['id'], $directory->importLegacy($this->tenant->id, 'Sumber lama')['id']);
        FundingSource::query()->findOrFail($first['id'])->update(['name' => 'Nama baru']);
        $this->assertSame($first['id'], $directory->importLegacy($this->tenant->id, 'Sumber lama')['id']);
        $other = $directory->importLegacy($this->createTenant()->id, 'Sumber lama');
        $this->assertNotSame($first['id'], $other['id']);
    }

    public function test_member_without_reference_management_permission_cannot_create(): void
    {
        $member = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $member->id, 'status' => 'active']);
        $this->actingAs($member)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/funding-sources', ['name' => 'Tidak boleh'])->assertForbidden();
    }

    private function source(string $code, ?string $tenantId = null): FundingSource
    {
        return FundingSource::query()->create(['tenant_id' => $tenantId ?? $this->tenant->id, 'code' => $code, 'name' => $code, 'active' => true])->refresh();
    }

    private function createTenant(): Tenant
    {
        $slug = 'funding-'.Str::lower(Str::random(10));
        $client = Client::create(['legal_name' => 'Klien uji', 'slug' => $slug, 'status' => 'active']);

        return Tenant::create(['client_id' => $client->id, 'name' => 'Tenant uji', 'slug' => $slug, 'status' => 'active']);
    }
}
