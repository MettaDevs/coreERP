<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class AssetRegisterTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => $this->awalanNomor('management-aset.aset').'-000001']], 200)]);
    }

    public function test_direct_receipt_keeps_receiver_usage_unit_and_custodian_separate(): void
    {
        $classification = $this->classification();
        $legalEntity = (string) Str::ulid();
        $receivingUnit = (string) Str::ulid();
        $usageUnit = (string) Str::ulid();
        $receiver = (string) Str::ulid();
        $custodian = (string) Str::ulid();

        $response = $this->sebagaiPengguna($this->tenantId, [
            'management-aset.aset.read', 'management-aset.aset.create', 'management-aset.aset.mutate',
        ])->withHeader('Idempotency-Key', 'receipt-1')->postJson('/api/modules/management-aset/v1/aset', [
            'legal_entity_id' => $legalEntity, 'nama' => 'Aset uji penerimaan', ...$classification,
            'acquired_on' => '2026-07-28', 'acquisition_value' => 12000000,
            'currency_code' => 'IDR', 'receiving_org_unit_id' => $receivingUnit,
            'usage_org_unit_id' => $usageUnit, 'received_by_user_id' => $receiver,
            'custodian_user_id' => $custodian,
        ])->assertCreated()->assertJsonPath('data.kode', $this->awalanNomor('management-aset.aset').'-000001');

        $assetId = $response->json('data.id');
        $this->assertDatabaseHas('aset_tr_penempatan_aset', [
            'tenant_id' => $this->tenantId, 'asset_id' => $assetId,
            'receiving_org_unit_id' => $receivingUnit, 'usage_org_unit_id' => $usageUnit,
            'received_by_user_id' => $receiver, 'custodian_user_id' => $custodian,
        ]);
        $this->assertSame(1, $this->jumlahNomorTerbit(), 'Penerbitan nomor tidak terjadi.');
    }

    public function test_mutation_adds_history_instead_of_rewriting_receipt(): void
    {
        $assetId = $this->receive();
        $newUnit = (string) Str::ulid();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate'])
            ->postJson('/api/modules/management-aset/v1/aset/'.$assetId.'/penempatan', [
                'effective_on' => '2026-08-01', 'reason' => 'Pindah pengguna', 'usage_org_unit_id' => $newUnit,
            ])->assertOk();

        $this->assertDatabaseCount('aset_tr_penempatan_aset', 2);
        $this->assertDatabaseHas('aset_tr_penempatan_aset', ['asset_id' => $assetId, 'usage_org_unit_id' => $newUnit, 'effective_on' => '2026-08-01']);
    }

    public function test_register_hides_assets_outside_the_signed_operating_unit_scope(): void
    {
        $firstLegalEntity = (string) Str::ulid();
        $secondLegalEntity = (string) Str::ulid();
        $firstUnit = (string) Str::ulid();
        $secondUnit = (string) Str::ulid();
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();
        $crossFirst = (string) Str::ulid();
        $crossSecond = (string) Str::ulid();
        $now = now();
        // Klasifikasi tidak diuji di sini; satu pasang dipakai bersama agar yang tersaring
        // benar-benar berasal dari legal entity dan operating unit.
        $scopeClassification = $this->classification();
        DB::table('aset_tr_penerimaan_aset')->insert([
            ['id' => $first, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-a', 'kode' => 'AST-SCOPE-A', 'nama' => 'Aset scope A', 'legal_entity_id' => $firstLegalEntity, 'responsible_org_unit_id' => $firstUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $second, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-b', 'kode' => 'AST-SCOPE-B', 'nama' => 'Aset scope B', 'legal_entity_id' => $secondLegalEntity, 'responsible_org_unit_id' => $secondUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $crossFirst, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-c', 'kode' => 'AST-SCOPE-C', 'nama' => 'Aset scope C', 'legal_entity_id' => $firstLegalEntity, 'responsible_org_unit_id' => $secondUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $crossSecond, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-d', 'kode' => 'AST-SCOPE-D', 'nama' => 'Aset scope D', 'legal_entity_id' => $secondLegalEntity, 'responsible_org_unit_id' => $firstUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $lingkup = [
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $firstLegalEntity, 'organization_id' => $firstUnit],
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $secondLegalEntity, 'organization_id' => $secondUnit],
        ];
        $data = $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'], $lingkup)->getJson('/api/modules/management-aset/v1/aset')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$first, $second], array_column($data, 'id'));
        $this->assertNotContains($crossFirst, array_column($data, 'id'));
        $this->assertNotContains($crossSecond, array_column($data, 'id'));
    }

    public function test_approved_decommissioning_event_stops_asset_use_once(): void
    {
        $workflowId = (string) Str::ulid();
        Http::swap(new Factory);
        Http::fake(fn ($request) => str_contains($request->url(), 'workflow-instances')
            ? Http::response(['data' => ['id' => $workflowId]], 201)
            : Http::response(['data' => ['number' => $this->awalanNomor('management-aset.aset').'-000001']], 200));
        $assetId = $this->receive();
        $asset = DB::table('aset_tr_penerimaan_aset')->where('id', $assetId)->first();
        $document = $this->sebagaiPengguna($this->tenantId, ['management-aset.dekomisioning-aset.create'])
            ->withHeader('Idempotency-Key', 'decommission-1')->postJson('/api/modules/management-aset/v1/dekomisioning-aset', [
                'legal_entity_id' => $asset->legal_entity_id, 'responsible_org_unit_id' => $asset->responsible_org_unit_id,
                'tanggal' => '2026-08-03', 'asset_id' => $assetId,
            ])->assertCreated()->json('data');

        $event = ['id' => (string) Str::ulid(), 'type' => 'core.workflow.decision.v2', 'tenant_id' => $this->tenantId,
            'correlation_id' => $document['id'], 'data' => [
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
        $this->call('POST', '/api/modules/management-aset/internal/v1/workflow-events', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/modules/management-aset/internal/v1/workflow-events', [], [], [], $headers, $body)->assertOk();

        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $assetId, 'lifecycle_state' => 'decommissioned']);
        $this->assertDatabaseCount('aset_processed_core_events', 1);
    }

    /**
     * Group yang sengaja tidak disusutkan: buku tetap terbentuk sebagai baris subledger,
     * tetapi tidak menuntut profil apa pun dan tidak menahan aset di status `received`.
     */
    public function test_group_without_depreciation_still_lets_the_asset_be_placed(): void
    {
        $classification = $this->classification();
        $this->configureNonDepreciatingBook($classification['group_aset_id']);

        $assetId = $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.create'])
            ->withHeader('Idempotency-Key', 'receipt-register-only')->postJson('/api/modules/management-aset/v1/aset', [
                'legal_entity_id' => (string) Str::ulid(), 'nama' => 'Aset tanpa penyusutan', ...$classification,
                'acquired_on' => '2026-07-28', 'acquisition_value' => 9000000,
                'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
            ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('aset_tr_buku_aset', [
            'asset_id' => $assetId, 'depreciate' => false, 'depreciation_profile_id' => null,
        ]);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate'])
            ->postJson('/api/modules/management-aset/v1/aset/'.$assetId.'/penempatan', [
                'effective_on' => '2026-08-01', 'reason' => 'Penempatan awal', 'usage_org_unit_id' => (string) Str::ulid(),
            ])->assertOk();
    }

    /**
     * Ambang kapitalisasi memakai jalur yang sama: aset murah tidak menyusut, dan
     * karenanya juga tidak boleh dituntut punya profil yang berlaku saat ditempatkan.
     */
    public function test_asset_below_capitalization_threshold_is_placed_without_a_profile(): void
    {
        $classification = $this->classification();
        $this->configureNonDepreciatingBook($classification['group_aset_id'], depreciate: true);
        DB::table('aset_m_group_aset')
            ->where(['tenant_id' => $this->tenantId, 'id' => $classification['group_aset_id']])
            ->update(['capitalization_threshold' => 1000000]);

        $assetId = $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.create'])
            ->withHeader('Idempotency-Key', 'receipt-below-threshold')->postJson('/api/modules/management-aset/v1/aset', [
                'legal_entity_id' => (string) Str::ulid(), 'nama' => 'Aset di bawah ambang', ...$classification,
                'acquired_on' => '2026-07-28', 'acquisition_value' => 400000,
                'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
            ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('aset_tr_buku_aset', ['asset_id' => $assetId, 'depreciate' => false]);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate'])
            ->postJson('/api/modules/management-aset/v1/aset/'.$assetId.'/penempatan', [
                'effective_on' => '2026-08-01', 'reason' => 'Penempatan awal', 'usage_org_unit_id' => (string) Str::ulid(),
            ])->assertOk();
    }

    /** Buku tanpa profil yang memang menghitung tetap ditolak; pagar itu tidak ikut dilepas. */
    public function test_depreciating_book_without_a_profile_still_blocks_placement(): void
    {
        $classification = $this->classification();
        $this->configureNonDepreciatingBook($classification['group_aset_id'], depreciate: true);

        $assetId = $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.create'])
            ->withHeader('Idempotency-Key', 'receipt-missing-profile')->postJson('/api/modules/management-aset/v1/aset', [
                'legal_entity_id' => (string) Str::ulid(), 'nama' => 'Aset tanpa profil', ...$classification,
                'acquired_on' => '2026-07-28', 'acquisition_value' => 9000000,
                'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
            ])->assertStatus(422)->json('data.id');

        $this->assertNull($assetId);
    }

    private function receive(): string
    {
        $classification = $this->classification();
        $this->configureReadyBook($classification['group_aset_id']);

        return $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.create'])
            ->withHeader('Idempotency-Key', 'receipt-test')->postJson('/api/modules/management-aset/v1/aset', [
                'legal_entity_id' => (string) Str::ulid(), 'nama' => 'Aset uji', ...$classification,
                'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
            ])->assertCreated()->json('data.id');
    }

    /**
     * Dua sumbu klasifikasi wajib milik aset, keduanya datar dan saling lepas.
     *
     * @return array{group_aset_id: string, jenis_aset_id: string}
     */
    private function classification(): array
    {
        $group = (string) Str::ulid();
        $type = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.Str::ulid(), 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $type];
    }

    private function configureReadyBook(string $groupId): void
    {
        $now = now();
        $profile = (string) Str::ulid();
        $book = (string) Str::ulid();
        DB::table('aset_m_profil_penyusutan')->insert([
            'id' => $profile, 'tenant_id' => $this->tenantId, 'creation_key' => 'profile-ready-'.Str::ulid(),
            'kode' => 'P'.Str::random(8), 'nama' => 'Profil siap', 'aktif' => true,
            'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar',
            'useful_life_periods' => 12, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $book, 'tenant_id' => $this->tenantId, 'creation_key' => 'book-ready-'.Str::ulid(),
            'kode' => 'B'.Str::random(8), 'nama' => 'Buku siap', 'aktif' => true,
            'posting_layer' => 'current', 'export_to_backoffice' => false, 'depreciation_profile_id' => $profile,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $groupId,
            'buku_id' => $book, 'depreciate' => true, 'useful_life_periods' => 12,
            'convention' => 'full_month', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Buku tanpa profil sama sekali. `depreciate` dibiarkan dapat dinyalakan supaya test
     * yang sama dapat menguji dua sisi pagar: buku yang memang tidak menghitung, dan
     * buku yang menghitung tetapi profilnya belum dipilih.
     */
    private function configureNonDepreciatingBook(string $groupId, bool $depreciate = false): void
    {
        $now = now();
        $book = (string) Str::ulid();
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $book, 'tenant_id' => $this->tenantId, 'creation_key' => 'book-register-'.Str::ulid(),
            'kode' => 'B'.Str::random(8), 'nama' => 'Buku register', 'aktif' => true,
            'posting_layer' => 'current', 'export_to_backoffice' => false, 'depreciation_profile_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $groupId,
            'buku_id' => $book, 'depreciate' => $depreciate, 'useful_life_periods' => null,
            'convention' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
