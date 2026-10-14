<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Reporting\Lists\AssetRegisterList;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\SeedsMaintenanceFixtures;
use Tests\TestCase;

class ActiveCompanyContextTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase, SeedsMaintenanceFixtures;

    private const API = '/api/modules/management-aset/v1/';

    private const PERMISSIONS = [
        'management-aset.aset.read',
        'management-aset.aset.update',
        'management-aset.polis-asuransi.read',
        'management-aset.polis-asuransi.create',
        'management-aset.penyusutan.post',
        'management-aset.permintaan-pembelian-aset.create',
        'management-aset.monitoring-aset.create',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    private string $secondCompanyId;

    private string $secondUnitId;

    private string $additionalUnitId;

    private string $membershipId;

    private string $firstAssetId;

    private string $secondAssetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->secondCompanyId = (string) Str::ulid();
        $this->unitId = (string) Str::ulid();
        $this->secondUnitId = (string) Str::ulid();
        $this->additionalUnitId = (string) Str::ulid();

        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->pastikanOrganisasiAda($this->tenantId, $this->secondCompanyId, 'legal_entity');
        foreach ([$this->unitId, $this->secondUnitId, $this->additionalUnitId] as $unitId) {
            $this->pastikanOrganisasiAda($this->tenantId, $unitId, 'operating_unit');
        }

        $typeId = $this->seedMaster('aset_m_jenis_aset', 'Jenis aset uji');
        $this->firstAssetId = $this->seedAsset($typeId, 'COMPANY-A');
        $this->seedAsset($typeId, 'COMPANY-A-OTHER-UNIT', ['responsible_org_unit_id' => $this->additionalUnitId]);
        $this->secondAssetId = $this->seedAsset($typeId, 'COMPANY-B', [
            'legal_entity_id' => $this->secondCompanyId, 'responsible_org_unit_id' => $this->secondUnitId,
        ]);

        $this->sebagaiPenggunaBernama('company-user', $this->tenantId, self::PERMISSIONS, [
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->unitId],
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->additionalUnitId],
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->secondCompanyId, 'organization_id' => $this->secondUnitId],
        ]);
        $this->membershipId = (string) TenantMembership::query()->where('user_id', $this->idPengguna('company-user'))->value('id');
        $this->switchCompany($this->legalEntityId, $this->unitId);
    }

    public function test_company_switch_separates_lists_details_and_updates_without_filtering_the_active_unit(): void
    {
        $this->getJson(self::API.'aset')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::API.'aset/'.$this->secondAssetId)->assertNotFound();
        $this->patchJson(self::API.'aset/'.$this->secondAssetId, ['nama' => 'Tidak boleh berubah'])->assertNotFound();
        $this->assertSame('Aset COMPANY-B', DB::table('aset_tr_aset')->where('id', $this->secondAssetId)->value('nama'));

        $this->switchCompany($this->secondCompanyId, $this->secondUnitId);
        $this->getJson(self::API.'aset')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->secondAssetId);
        $this->getJson(self::API.'aset/'.$this->secondAssetId)->assertOk();
        $this->getJson(self::API.'aset/'.$this->firstAssetId)->assertNotFound();
    }

    public function test_company_ownership_is_required_for_creation_and_idempotent_replay(): void
    {
        $vendorId = $this->pastikanVendorUji($this->tenantId, $this->secondCompanyId);
        $payload = [
            'legal_entity_id' => $this->secondCompanyId,
            'nama' => 'Polis uji perusahaan', 'nomor_polis' => 'POLICY-B',
            'vendor_id' => $vendorId, 'berlaku_mulai' => '2026-01-01',
            'premi_tahunan' => 1000, 'nilai_pertanggungan' => 1000000,
        ];
        $key = 'company-policy-'.Str::ulid();

        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'polis-asuransi', $payload)->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
        $this->assertSame(0, DB::table('aset_m_polis_asuransi')->where('creation_key', $key)->count());

        $this->switchCompany($this->secondCompanyId, $this->secondUnitId);
        $created = $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'polis-asuransi', $payload)->assertCreated();
        $policyId = (string) $created->json('data.id');
        $this->switchCompany($this->legalEntityId, $this->unitId);
        $this->getJson(self::API.'polis-asuransi/'.$policyId)->assertNotFound();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::API.'polis-asuransi', $payload)->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
        $this->assertSame(1, DB::table('aset_m_polis_asuransi')->where('creation_key', $key)->count());
    }

    public function test_an_asset_cannot_become_a_component_of_another_companys_asset(): void
    {
        $this->patchJson(self::API.'aset/'.$this->firstAssetId, [
            'induk_aset_id' => $this->secondAssetId,
        ])->assertUnprocessable()->assertJsonValidationErrors('induk_aset_id');
        $this->assertNull(DB::table('aset_tr_aset')->where('id', $this->firstAssetId)->value('induk_aset_id'));
    }

    public function test_posting_cannot_target_another_authorized_company(): void
    {
        $this->postJson(self::API.'penyusutan/posting', [
            'legal_entity_id' => $this->secondCompanyId,
            'buku_id' => (string) Str::ulid(), 'period_ends_on' => '2026-10-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
        $this->assertSame(0, DB::table('aset_tr_penyusutan_aset')->count());
    }

    public function test_procurement_cannot_link_a_planning_line_from_another_company(): void
    {
        $planId = (string) Str::ulid();
        $lineId = (string) Str::ulid();
        $typeId = (string) DB::table('aset_tr_aset')->where('id', $this->firstAssetId)->value('jenis_aset_id');
        DB::table('aset_tr_perencanaan_aset')->insert([
            'id' => $planId, 'tenant_id' => $this->tenantId, 'creation_key' => 'plan-'.Str::ulid(), 'kode' => 'PLAN-B',
            'legal_entity_id' => $this->secondCompanyId, 'planning_org_unit_id' => $this->secondUnitId,
            'planned_on' => '2026-01-01', 'planning_year' => 2026, 'planning_type' => 'regular',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aset_tr_perencanaan_aset_details')->insert([
            'id' => $lineId, 'tenant_id' => $this->tenantId, 'planning_id' => $planId, 'line_number' => 1,
            'jenis_aset_id' => $typeId, 'nama_aset' => 'Aset rencana', 'unit' => 'Unit', 'quantity' => 1,
            'requested_specification' => 'Spesifikasi uji', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', 'procurement-'.Str::ulid())->postJson(self::API.'permintaan-pembelian-aset', [
            'legal_entity_id' => $this->legalEntityId, 'requesting_org_unit_id' => $this->unitId, 'requested_on' => '2026-01-01',
            'details' => [['planning_detail_id' => $lineId, 'jenis_aset_id' => $typeId, 'satuan_id' => (string) Str::ulid(), 'quantity' => 1, 'specification' => 'Spesifikasi uji']],
        ])->assertUnprocessable()->assertJsonValidationErrors('details.0.planning_detail_id');
        $this->assertSame(0, DB::table('aset_tr_permintaan_pengadaan_aset')->count());
    }

    public function test_unrestricted_organization_access_does_not_bypass_company_for_monitoring_without_a_unit(): void
    {
        $this->sebagaiPenggunaBernama('company-user', $this->tenantId, self::PERMISSIONS);
        $this->switchCompany($this->legalEntityId, $this->unitId);
        $locationId = $this->seedMaster('aset_m_lokasi_aset', 'Lokasi uji');

        $this->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())->postJson(self::API.'monitoring-aset', [
            'legal_entity_id' => $this->secondCompanyId, 'responsible_org_unit_id' => null,
            'lokasi_aset_id' => $locationId, 'tanggal' => '2026-01-01', 'details' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
        $this->assertSame(0, DB::table('aset_tr_monitoring_aset')->count());
    }

    public function test_export_uses_the_saved_company_context_with_all_company_grants(): void
    {
        $source = app(AssetRegisterList::class);
        $context = [
            'tenant_id' => $this->tenantId, 'legal_entity_id' => $this->legalEntityId,
            'org_unit_id' => $this->unitId, 'user_id' => $this->idPengguna('company-user'),
            'permissions' => self::PERMISSIONS, 'timezone' => 'UTC',
            'data_policies' => ['management-aset.asset-responsibility' => ['all' => false, 'scope_grants' => [
                ['legal_entity_id' => $this->legalEntityId, 'operating_unit_ids' => [$this->unitId, $this->additionalUnitId]],
                ['legal_entity_id' => $this->secondCompanyId, 'operating_unit_ids' => [$this->secondUnitId]],
            ]]],
        ];
        $this->switchCompany($this->secondCompanyId, $this->secondUnitId);

        $rows = app(TenantRunner::class)->runFor($this->tenantId, fn () => iterator_to_array($source->rows($context, [], ['column' => 'kode', 'direction' => 'asc']), false));
        $this->assertSame(['COMPANY-A', 'COMPANY-A-OTHER-UNIT'], array_column($rows, 'kode'));
        $context['legal_entity_id'] = $this->secondCompanyId;
        $rows = app(TenantRunner::class)->runFor($this->tenantId, fn () => iterator_to_array($source->rows($context, [], null), false));
        $this->assertSame(['COMPANY-B'], array_column($rows, 'kode'));
    }

    private function switchCompany(string $legalEntityId, string $unitId): void
    {
        $this->putJson('/api/v1/workspace-context', [
            'membership_id' => $this->membershipId, 'legal_entity_id' => $legalEntityId, 'org_unit_id' => $unitId,
        ])->assertOk()->assertJsonPath('data.legal_entity_id', $legalEntityId);
    }
}
