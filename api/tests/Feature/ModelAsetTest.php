<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class ModelAsetTest extends TestCase
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
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'MDLA-000001']], 200)]);

        $created = $this->withContext([
            'management-aset.model-aset.read',
            'management-aset.model-aset.create',
            'management-aset.model-aset.update',
            'management-aset.model-aset.archive',
        ])->withHeader('Idempotency-Key', 'create-model-1')
            ->postJson('/api/v1/model-aset', ['nama' => 'PC200-8', 'keterangan' => 'Excavator 20 ton', 'pabrikan_aset_id' => $this->pabrikan()])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'MDLA-000001');

        $id = $created->json('data.id');
        Http::assertSent(fn ($request) => $request->hasHeader('X-CoreERP-Tenant-Id', $this->tenantId)
            && $request['idempotency_key'] === 'model-aset:create-model-1');

        $this->withContext(['management-aset.model-aset.update'])
            ->patchJson('/api/v1/model-aset/'.$id, ['nama' => 'PC200-8 MK2', 'aktif' => false])
            ->assertOk()
            ->assertJsonPath('data.aktif', false);

        $this->withContext(['management-aset.model-aset.archive'])
            ->deleteJson('/api/v1/model-aset/'.$id)
            ->assertNoContent();

        $this->assertSoftDeleted('m_model_aset', ['id' => $id, 'tenant_id' => $this->tenantId]);
    }

    public function test_retry_is_idempotent_and_tenants_cannot_read_each_others_records(): void
    {
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'MDLA-000001']], 200)]);
        $pabrikan = $this->pabrikan();

        $first = $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/model-aset', ['nama' => 'Pertama', 'pabrikan_aset_id' => $pabrikan])
            ->assertCreated();
        $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/model-aset', ['nama' => 'Pertama', 'pabrikan_aset_id' => $pabrikan])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/model-aset', ['nama' => 'Data berbeda', 'pabrikan_aset_id' => $pabrikan])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        Http::assertSentCount(1);

        $otherTenant = (string) Str::ulid();
        $this->withHeaders($this->contextHeaders($otherTenant, ['management-aset.model-aset.read']))
            ->getJson('/api/v1/model-aset/'.$first->json('data.id'))
            ->assertNotFound();
    }

    public function test_gateway_context_and_permission_are_required(): void
    {
        $this->getJson('/api/v1/model-aset')->assertUnauthorized();
        $this->withContext([])->getJson('/api/v1/model-aset')->assertForbidden();
        $token = $this->contextToken($this->tenantId, ['management-aset.model-aset.read']);
        $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $this->withHeader('Authorization', 'Bearer '.$tampered)
            ->getJson('/api/v1/model-aset')
            ->assertUnauthorized();
    }

    /** @param list<string> $permissions */
    private function withContext(array $permissions): static
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $permissions));
    }

    /** Induk wajib model aset; datar, jadi cukup satu insert tanpa rantai apa pun. */
    private function pabrikan(): string
    {
        $now = now();
        $id = (string) Str::ulid();
        DB::table('m_pabrikan_aset')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'creation_key' => 'pabrikan-'.Str::ulid(),
            'kode' => 'P'.Str::random(6),
            'nama' => 'Pabrikan',
            'aktif' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
