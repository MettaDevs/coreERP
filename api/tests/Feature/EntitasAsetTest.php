<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class EntitasAsetTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
    }

    public function test_crud_uses_core_number_sequence_and_archives_instead_of_deleting(): void
    {
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'EA-000001']], 200)]);

        $created = $this->withContext([
            'management-aset.entitas-aset.read',
            'management-aset.entitas-aset.create',
            'management-aset.entitas-aset.update',
            'management-aset.entitas-aset.archive',
        ])->withHeader('Idempotency-Key', 'create-entity-1')
            ->postJson('/api/v1/entitas-aset', ['nama' => 'Entitas Induk', 'keterangan' => 'Kantor pusat', 'jenis_aset_id' => $this->jenis()])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'EA-000001');

        $id = $created->json('data.id');
        Http::assertSent(fn ($request) => $request->hasHeader('X-CoreERP-Tenant-Id', $this->tenantId)
            && $request['idempotency_key'] === 'entitas-aset:create-entity-1');

        $this->withContext(['management-aset.entitas-aset.update'])
            ->patchJson('/api/v1/entitas-aset/'.$id, ['nama' => 'Entitas Utama', 'aktif' => false])
            ->assertOk()
            ->assertJsonPath('data.aktif', false);

        $this->withContext(['management-aset.entitas-aset.archive'])
            ->deleteJson('/api/v1/entitas-aset/'.$id)
            ->assertNoContent();

        $this->assertSoftDeleted('m_entitas_aset', ['id' => $id, 'tenant_id' => $this->tenantId]);
    }

    public function test_retry_is_idempotent_and_tenants_cannot_read_each_others_records(): void
    {
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'EA-000001']], 200)]);
        $jenis = $this->jenis();

        $first = $this->withContext(['management-aset.entitas-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/entitas-aset', ['nama' => 'Pertama', 'jenis_aset_id' => $jenis])
            ->assertCreated();
        $this->withContext(['management-aset.entitas-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/entitas-aset', ['nama' => 'Pertama', 'jenis_aset_id' => $jenis])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->withContext(['management-aset.entitas-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/entitas-aset', ['nama' => 'Data berbeda', 'jenis_aset_id' => $jenis])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        Http::assertSentCount(1);

        $otherTenant = (string) Str::ulid();
        $this->withHeaders($this->contextHeaders($otherTenant, ['management-aset.entitas-aset.read']))
            ->getJson('/api/v1/entitas-aset/'.$first->json('data.id'))
            ->assertNotFound();
    }

    public function test_gateway_context_and_permission_are_required(): void
    {
        $this->getJson('/api/v1/entitas-aset')->assertUnauthorized();
        $this->withContext([])->getJson('/api/v1/entitas-aset')->assertForbidden();
        $token = $this->contextToken($this->tenantId, ['management-aset.entitas-aset.read']);
        $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $this->withHeader('Authorization', 'Bearer '.$tampered)
            ->getJson('/api/v1/entitas-aset')
            ->assertUnauthorized();
    }

    /** @param list<string> $permissions */
    private function withContext(array $permissions): static
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $permissions));
    }

    private function jenis(): string
    {
        $now = now(); $group = (string) Str::ulid(); $category = (string) Str::ulid(); $type = (string) Str::ulid();
        DB::table('m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.Str::ulid(), 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('m_kategori_aset')->insert(['id' => $category, 'tenant_id' => $this->tenantId, 'creation_key' => 'category-'.Str::ulid(), 'group_aset_id' => $group, 'kode' => 'K'.Str::random(6), 'nama' => 'Kategori', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.Str::ulid(), 'kategori_aset_id' => $category, 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        return $type;
    }
}
