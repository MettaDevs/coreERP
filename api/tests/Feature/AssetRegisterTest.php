<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class AssetRegisterTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'AST-000001']], 200)]);
    }

    public function test_direct_receipt_keeps_receiver_usage_unit_and_custodian_separate(): void
    {
        $jenis = $this->jenis();
        $legalEntity = (string) Str::ulid();
        $receivingUnit = (string) Str::ulid();
        $usageUnit = (string) Str::ulid();
        $receiver = (string) Str::ulid();
        $custodian = (string) Str::ulid();

        $response = $this->withHeaders($this->contextHeaders($this->tenantId, [
            'management-aset.aset.read', 'management-aset.aset.create', 'management-aset.aset.mutate',
        ]))->withHeader('Idempotency-Key', 'receipt-1')->postJson('/api/v1/aset', [
            'legal_entity_id' => $legalEntity, 'jenis_aset_id' => $jenis,
            'acquired_on' => '2026-07-28', 'acquisition_value' => 12000000,
            'currency_code' => 'IDR', 'receiving_org_unit_id' => $receivingUnit,
            'usage_org_unit_id' => $usageUnit, 'received_by_user_id' => $receiver,
            'custodian_user_id' => $custodian,
        ])->assertCreated()->assertJsonPath('data.kode', 'AST-000001');

        $assetId = $response->json('data.id');
        $this->assertDatabaseHas('tr_penempatan_aset', [
            'tenant_id' => $this->tenantId, 'asset_id' => $assetId,
            'receiving_org_unit_id' => $receivingUnit, 'usage_org_unit_id' => $usageUnit,
            'received_by_user_id' => $receiver, 'custodian_user_id' => $custodian,
        ]);
        Http::assertSent(fn ($request) => $request['legal_entity_id'] === $legalEntity);
    }

    public function test_mutation_adds_history_instead_of_rewriting_receipt(): void
    {
        $assetId = $this->receive();
        $newUnit = (string) Str::ulid();
        $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.mutate']))
            ->postJson('/api/v1/aset/'.$assetId.'/penempatan', [
                'effective_on' => '2026-08-01', 'reason' => 'Pindah pengguna', 'usage_org_unit_id' => $newUnit,
            ])->assertOk();

        $this->assertDatabaseCount('tr_penempatan_aset', 2);
        $this->assertDatabaseHas('tr_penempatan_aset', ['asset_id' => $assetId, 'usage_org_unit_id' => $newUnit, 'effective_on' => '2026-08-01']);
    }

    public function test_register_hides_assets_outside_the_signed_operating_unit_scope(): void
    {
        $firstLegalEntity = (string) Str::ulid(); $secondLegalEntity = (string) Str::ulid();
        $firstUnit = (string) Str::ulid(); $secondUnit = (string) Str::ulid();
        $first = (string) Str::ulid(); $second = (string) Str::ulid(); $crossFirst = (string) Str::ulid(); $crossSecond = (string) Str::ulid(); $now = now();
        DB::table('tr_penerimaan_aset')->insert([
            ['id' => $first, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-a', 'kode' => 'AST-SCOPE-A', 'legal_entity_id' => $firstLegalEntity, 'responsible_org_unit_id' => $firstUnit, 'jenis_aset_id' => $this->jenis(), 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $second, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-b', 'kode' => 'AST-SCOPE-B', 'legal_entity_id' => $secondLegalEntity, 'responsible_org_unit_id' => $secondUnit, 'jenis_aset_id' => $this->jenis(), 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $crossFirst, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-c', 'kode' => 'AST-SCOPE-C', 'legal_entity_id' => $firstLegalEntity, 'responsible_org_unit_id' => $secondUnit, 'jenis_aset_id' => $this->jenis(), 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $crossSecond, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-d', 'kode' => 'AST-SCOPE-D', 'legal_entity_id' => $secondLegalEntity, 'responsible_org_unit_id' => $firstUnit, 'jenis_aset_id' => $this->jenis(), 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $scope = ['all' => false, 'scope_grants' => [
            ['legal_entity_id' => $firstLegalEntity, 'operating_unit_ids' => [$firstUnit]],
            ['legal_entity_id' => $secondLegalEntity, 'operating_unit_ids' => [$secondUnit]],
        ]];
        $data = $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.read'], ['data_policies' => ['management-aset.asset-responsibility' => $scope]]))->getJson('/api/v1/aset')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$first, $second], array_column($data, 'id'));
        $this->assertNotContains($crossFirst, array_column($data, 'id'));
        $this->assertNotContains($crossSecond, array_column($data, 'id'));
    }

    public function test_approved_decommissioning_event_stops_asset_use_once(): void
    {
        $workflowId = (string) Str::ulid();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn ($request) => str_contains($request->url(), 'workflow-instances')
            ? Http::response(['data' => ['id' => $workflowId]], 201)
            : Http::response(['data' => ['number' => 'AST-000001']], 200));
        $assetId = $this->receive();
        $asset = DB::table('tr_penerimaan_aset')->where('id', $assetId)->first();
        $document = $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.dekomisioning-aset.create']))
            ->withHeader('Idempotency-Key', 'decommission-1')->postJson('/api/v1/dekomisioning-aset', [
                'legal_entity_id' => $asset->legal_entity_id, 'responsible_org_unit_id' => $asset->responsible_org_unit_id,
                'tanggal' => '2026-08-03', 'asset_id' => $assetId,
            ])->assertCreated()->json('data');

        $event = ['id' => (string) Str::ulid(), 'type' => 'core.workflow.decision.v1', 'tenant_id' => $this->tenantId, 'data' => [
            'workflow_instance_id' => $workflowId, 'workflow_type' => 'management-aset.dekomisioning-aset-verification',
            'decision' => 'approved', 'source_document_type' => 'dekomisioning-aset', 'source_document_id' => $document['id'],
            'decision_context' => ['asset_id' => $assetId],
        ]];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $headers = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_COREERP_EVENT_TIMESTAMP' => $timestamp,
            'HTTP_X_COREERP_EVENT_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'test-context-signing-key-32-bytes'),
        ];
        $this->call('POST', '/api/internal/v1/workflow-events', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/internal/v1/workflow-events', [], [], [], $headers, $body)->assertOk();

        $this->assertDatabaseHas('tr_penerimaan_aset', ['id' => $assetId, 'lifecycle_state' => 'decommissioned']);
        $this->assertDatabaseCount('processed_core_events', 1);
    }

    private function receive(): string
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.create']))
            ->withHeader('Idempotency-Key', 'receipt-test')->postJson('/api/v1/aset', [
                'legal_entity_id' => (string) Str::ulid(), 'jenis_aset_id' => $this->jenis(),
                'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
            ])->assertCreated()->json('data.id');
    }


    private function jenis(): string
    {
        $group = (string) Str::ulid(); $category = (string) Str::ulid(); $type = (string) Str::ulid();
        $now = now();
        DB::table('m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.Str::ulid(), 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('m_kategori_aset')->insert(['id' => $category, 'tenant_id' => $this->tenantId, 'creation_key' => 'category-'.Str::ulid(), 'group_aset_id' => $group, 'kode' => 'K'.Str::random(6), 'nama' => 'Kategori', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.Str::ulid(), 'kategori_aset_id' => $category, 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        return $type;
    }
}
