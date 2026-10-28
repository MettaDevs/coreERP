<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\PlanningYearDirectory;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PerencanaanAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    private string $unitId;

    private string $fundingSourceId;

    private string $responsibleUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->orgUnitId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->orgUnitId, 'operating_unit');
        $this->unitId = $this->buatSatuanUji($this->tenantId);
        $this->sebagaiPenggunaBernama('planning-author', $this->tenantId, ['management-aset.perencanaan-aset.create']);
        $this->responsibleUserId = (string) auth()->id();
        $this->fundingSourceId = $this->fundingSource($this->tenantId);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/units-of-measure/resolve')) {
                return Http::response(['data' => [['id' => $this->unitId, 'code' => 'EA', 'name' => 'Unit', 'symbol' => null, 'decimal_places' => 0]]], 200);
            }

            return Http::response(['data' => ['number' => 'PLNA-000001']], 200);
        });
    }

    private function configurePlanningSequence(bool $allowManual, bool $allowAutomatic): void
    {
        $referenceId = DB::table('app_number_sequence_references')->where('code', 'management-aset.perencanaan-aset')->value('id');
        DB::table('tenant_number_sequences')->where('tenant_id', $this->tenantId)->where('reference_id', $referenceId)->update([
            'allow_manual' => $allowManual, 'allow_automatic' => $allowAutomatic,
        ]);
    }

    public function test_manual_planning_code_is_saved_and_replayed_without_another_number(): void
    {
        $this->configurePlanningSequence(true, true);
        $payload = [...$this->payload($this->jenis($this->tenantId)), 'kode' => 'RENCANA-KANTOR'];
        $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'manual-plan');
        $created = $this->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)->assertCreated()->assertJsonPath('data.kode', 'RENCANA-KANTOR');
        $this->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, $this->jumlahNomorTerbit());
    }

    public function test_manual_only_planning_requires_a_code_and_reports_its_policy(): void
    {
        $this->configurePlanningSequence(true, false);
        $this->headers(['management-aset.perencanaan-aset.create'])
            ->getJson('/api/modules/management-aset/v1/perencanaan-aset/number-policy')->assertOk()
            ->assertJsonPath('data.allow_manual', true)->assertJsonPath('data.allow_automatic', false);
        $this->create($this->jenis($this->tenantId))->assertUnprocessable();
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_automatic_only_planning_rejects_manual_codes(): void
    {
        $this->configurePlanningSequence(false, true);
        $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'rejected-manual')
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', [...$this->payload($this->jenis($this->tenantId)), 'kode' => 'RENCANA-KANTOR'])->assertUnprocessable();
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_planning_number_policy_requires_create_permission(): void
    {
        $this->headers(['management-aset.perencanaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/perencanaan-aset/number-policy')->assertForbidden();
    }

    public function test_planning_numbers_can_repeat_in_another_legal_entity(): void
    {
        $typeId = $this->jenis($this->tenantId);
        $first = $this->create($typeId)->assertCreated()->json('data');
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->selectCompany($this->tenantId, $this->legalEntityId);
        $second = $this->create($typeId)->assertCreated()->json('data');
        $this->assertSame($first['kode'], $second['kode']);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function test_planning_uses_aset_type_lookup_and_saves_specification_on_transaction_detail(): void
    {
        $jenis = $this->jenis($this->tenantId);
        $response = $this->create($jenis)->assertCreated()->assertJsonPath('data.kode', 'PLNA-000001');
        $planId = $response->json('data.id');

        $this->assertDatabaseHas('aset_tr_perencanaan_aset', [
            'id' => $planId, 'tenant_id' => $this->tenantId, 'planning_org_unit_id' => $this->orgUnitId,
            'responsible_user_id' => $this->responsibleUserId,
        ]);
        $this->assertDatabaseHas('aset_tr_perencanaan_aset_details', [
            'planning_id' => $planId, 'jenis_aset_id' => $jenis, 'nama_aset' => 'Laptop kerja',
            'requested_specification' => 'RAM 16 GB, SSD 512 GB', 'quantity' => 2,
        ]);
        // Dulu memeriksa entitas legal yang dikirim ke Core lewat kabel; sekarang memeriksa
        // nomor yang benar-benar terbit. Satu baris penerbitan berarti penghitungnya maju.
        $this->assertSame(1, $this->jumlahNomorTerbit(), 'Penerbitan nomor tidak terjadi.');

        $this->headers(['management-aset.perencanaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/perencanaan-aset/'.$planId)
            ->assertOk()->assertJsonPath('data.details.0.jenis_aset_nama', 'Laptop kerja');
    }

    public function test_rejects_aset_type_from_another_tenant_before_issuing_a_number(): void
    {
        $otherTenantType = $this->jenis((string) Str::ulid());
        $this->create($otherTenantType)->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertDatabaseCount('aset_tr_perencanaan_aset', 0);
        $this->assertSame(0, $this->jumlahNomorTerbit(), 'Ada nomor yang terbit padahal seharusnya tidak.');
    }

    public function test_update_and_archive_require_their_own_permissions_and_current_version(): void
    {
        $jenis = $this->jenis($this->tenantId);
        $plan = $this->create($jenis)->assertCreated()->json('data');
        $payload = $this->payload($jenis);
        $payload['description'] = 'Kebutuhan diperbarui';
        $payload['version'] = 1;

        $this->headers(['management-aset.perencanaan-aset.read'])
            ->patchJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], $payload)->assertForbidden();
        $versi = $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update'])
            ->patchJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], $payload)->assertOk()->json('data.version');
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], ['version' => 1])->assertConflict();
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], ['version' => $versi])->assertNoContent();
        $this->assertSoftDeleted('aset_tr_perencanaan_aset', ['id' => $plan['id']]);
    }

    public function test_simpan_kedua_dengan_versi_yang_sama_ditolak_dan_rincian_simpan_pertama_bertahan(): void
    {
        $jenis = $this->jenis($this->tenantId);
        $plan = $this->create($jenis)->assertCreated()->assertJsonPath('data.version', 1)->json('data');
        $alamat = '/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'];
        $pengubah = $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update']);

        $pertama = $this->payload($jenis);
        $pertama['description'] = 'Simpan pertama';
        $pertama['details'][0]['requested_specification'] = 'Spesifikasi simpan pertama';
        $pengubah->patchJson($alamat, [...$pertama, 'version' => 1])->assertOk();

        $kedua = $this->payload($jenis);
        $kedua['description'] = 'Simpan kedua';
        $kedua['details'][0]['requested_specification'] = 'Spesifikasi simpan kedua';
        $pengubah->patchJson($alamat, [...$kedua, 'version' => 1])
            ->assertConflict()
            ->assertJsonPath('error.code', 'stale_version')
            ->assertJsonPath('error.message', RowVersion::STALE_MESSAGE);

        $this->assertDatabaseHas('aset_tr_perencanaan_aset', ['id' => $plan['id'], 'description' => 'Simpan pertama']);
        $this->assertSame(
            ['Spesifikasi simpan pertama'],
            DB::table('aset_tr_perencanaan_aset_details')->where('planning_id', $plan['id'])->whereNull('deleted_at')->pluck('requested_specification')->all(),
        );
    }

    public function test_simpan_dan_arsip_tanpa_versi_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $jenis = $this->jenis($this->tenantId);
        $plan = $this->create($jenis)->assertCreated()->json('data');
        $alamat = '/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'];
        $payload = $this->payload($jenis);
        $payload['description'] = 'Tanpa versi';

        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update'])
            ->patchJson($alamat, $payload)->assertStatus(428)->assertJsonPath('error.code', 'version_required');
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.archive'])
            ->deleteJson($alamat)->assertStatus(428);

        $this->assertDatabaseHas('aset_tr_perencanaan_aset', [
            'id' => $plan['id'], 'description' => 'Perangkat tim pengembangan', 'version' => 1, 'deleted_at' => null,
        ]);
    }

    public function test_rincian_memulangkan_versi_dan_etag(): void
    {
        $plan = $this->create($this->jenis($this->tenantId))->assertCreated()->json('data');

        $this->headers(['management-aset.perencanaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'])
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertHeader('ETag', RowVersion::etag(1));
    }

    public function test_future_planning_year_is_independent_from_document_date(): void
    {
        $payload = $this->payload($this->jenis($this->tenantId));
        $payload['planning_year_id'] = $this->planningYear(2027);
        $payload['details'][0]['needed_on'] = '2027-02-01';

        $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'future-year')
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
            ->assertCreated()->assertJsonPath('data.planning_year', 2027);
        $this->assertDatabaseHas('aset_tr_perencanaan_aset_details', ['needed_on' => '2027-02-01']);
    }

    public function test_needed_date_must_belong_to_selected_planning_year(): void
    {
        $payload = $this->payload($this->jenis($this->tenantId));
        $payload['details'][0]['needed_on'] = '2027-02-01';

        $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'wrong-year')
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('details.0.needed_on');
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_responsible_person_can_be_another_active_tenant_member(): void
    {
        $this->sebagaiPenggunaBernama('planning-responsible', $this->tenantId, ['management-aset.perencanaan-aset.read']);
        $selectedUser = (string) auth()->id();
        $payload = $this->payload($this->jenis($this->tenantId));
        $payload['responsible_user_id'] = $selectedUser;

        $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'selected-person')
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
            ->assertCreated()->assertJsonPath('data.responsible_user_id', $selectedUser);
    }

    public function test_inactive_or_foreign_responsible_user_is_rejected(): void
    {
        $this->sebagaiPenggunaBernama('inactive-responsible', $this->tenantId, ['management-aset.perencanaan-aset.read']);
        $inactiveUser = (string) auth()->id();
        DB::table('tenant_memberships')->where('tenant_id', $this->tenantId)->where('user_id', $inactiveUser)->update(['status' => 'inactive']);
        $this->sebagaiPenggunaBernama('foreign-responsible', $this->buatTenantUji(), ['management-aset.perencanaan-aset.read']);
        $foreignUser = (string) auth()->id();
        $payload = $this->payload($this->jenis($this->tenantId));

        foreach ([$inactiveUser, $foreignUser] as $selectedUser) {
            $payload['responsible_user_id'] = $selectedUser;
            $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'invalid-person-'.Str::ulid())
                ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('responsible_user_id');
        }
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_funding_source_must_be_active_and_belong_to_the_tenant(): void
    {
        $payload = $this->payload($this->jenis($this->tenantId));
        $invalidSources = [$this->fundingSource($this->tenantId, false), $this->fundingSource($this->buatTenantUji())];

        foreach ($invalidSources as $sourceId) {
            $payload['details'][0]['funding_source_id'] = $sourceId;
            $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'invalid-fund-'.Str::ulid())
                ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('details.0.funding_source_id');
        }
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_unknown_price_stays_null_and_funding_source_is_snapshotted_per_line(): void
    {
        $payload = $this->payload($this->jenis($this->tenantId));
        $payload['details'][0]['estimated_unit_price'] = null;
        $otherSource = $this->fundingSource($this->tenantId);
        $payload['details'][] = [...$payload['details'][0], 'funding_source_id' => $otherSource, 'estimated_unit_price' => 0];
        $plan = $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'nullable-estimate')
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)->assertCreated()->json('data');

        $this->assertDatabaseHas('aset_tr_perencanaan_aset_details', [
            'planning_id' => $plan['id'], 'funding_source_id' => $this->fundingSourceId,
            'funding_source_name' => DB::table('funding_sources')->where('id', $this->fundingSourceId)->value('name'),
            'estimated_unit_price' => null, 'estimated_total_price' => null,
        ]);
        $this->assertDatabaseHas('aset_tr_perencanaan_aset_details', [
            'planning_id' => $plan['id'], 'funding_source_id' => $otherSource,
            'estimated_unit_price' => 0, 'estimated_total_price' => 0,
        ]);
    }

    public function test_saved_line_can_retain_its_inactive_funding_source(): void
    {
        $type = $this->jenis($this->tenantId);
        $plan = $this->create($type)->assertCreated()->json('data');
        $lineId = DB::table('aset_tr_perencanaan_aset_details')->where('planning_id', $plan['id'])->value('id');
        $originalName = DB::table('aset_tr_perencanaan_aset_details')->where('id', $lineId)->value('funding_source_name');
        DB::table('funding_sources')->where('id', $this->fundingSourceId)->update(['active' => false, 'name' => 'Nama master diperbarui']);
        $payload = $this->payload($type);
        $payload['details'][0]['id'] = $lineId;
        $payload['description'] = 'Penjelasan diperbarui';

        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update'])
            ->patchJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], [...$payload, 'version' => $plan['version']])
            ->assertOk();
        $this->assertDatabaseHas('aset_tr_perencanaan_aset_details', ['id' => $lineId, 'funding_source_id' => $this->fundingSourceId, 'funding_source_name' => $originalName]);
    }

    public function test_planning_year_id_is_required_and_controls_the_snapshot_year(): void
    {
        $payload = $this->payload($this->jenis($this->tenantId));
        unset($payload['planning_year_id']);
        $payload['planning_year'] = 2026;
        $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'missing-year-id')
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('planning_year_id');
        $payload['planning_year_id'] = $this->planningYear(2026);
        $payload['planning_year'] = 2099;
        $this->withHeader('Idempotency-Key', 'year-from-master')->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
            ->assertCreated()->assertJsonPath('data.planning_year', 2026)->assertJsonPath('data.planning_year_id', $payload['planning_year_id']);
    }

    public function test_planning_year_from_another_company_is_rejected(): void
    {
        $otherCompany = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $otherCompany, 'legal_entity');
        $payload = $this->payload($this->jenis($this->tenantId));
        $payload['planning_year_id'] = app(PlanningYearDirectory::class)->importLegacy($this->tenantId, $otherCompany, 2026)['id'];
        $this->headers(['management-aset.perencanaan-aset.create'])->withHeader('Idempotency-Key', 'foreign-company-year')
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('planning_year_id');
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_inactive_year_is_hidden_from_new_choices_but_retained_by_saved_document(): void
    {
        $type = $this->jenis($this->tenantId);
        $plan = $this->create($type)->assertCreated()->json('data');
        DB::table('planning_years')->where('id', $plan['planning_year_id'])->update(['active' => false]);
        $this->create($type)->assertUnprocessable()->assertJsonValidationErrors('planning_year_id');
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update'])
            ->getJson('/api/modules/management-aset/v1/perencanaan-aset/planning-years?legal_entity_id='.$this->legalEntityId)
            ->assertOk()->assertJsonCount(0, 'data');
        $payload = $this->payload($type);
        $this->patchJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], [...$payload, 'version' => $plan['version']])
            ->assertOk()->assertJsonPath('data.planning_year_id', $plan['planning_year_id']);
    }

    public function test_organization_choices_follow_policy_grants_and_do_not_expand_from_requested_ids(): void
    {
        $otherCompany = (string) Str::ulid();
        $otherUnit = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $otherCompany, 'legal_entity');
        $this->pastikanOrganisasiAda($this->tenantId, $otherUnit, 'operating_unit');
        $this->sebagaiPenggunaBernama('planning-author', $this->tenantId, ['management-aset.perencanaan-aset.create'], [
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->orgUnitId],
        ]);
        $this->getJson('/api/modules/management-aset/v1/perencanaan-aset/organizations?kind=legal_entities')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->legalEntityId);
        $this->getJson('/api/modules/management-aset/v1/perencanaan-aset/organizations?kind=legal_entities&record_ids[]='.$otherCompany)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/modules/management-aset/v1/perencanaan-aset/organizations?kind=operating_units&legal_entity_id='.$this->legalEntityId)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->orgUnitId);
        $this->getJson('/api/modules/management-aset/v1/perencanaan-aset/planning-years?legal_entity_id='.$otherCompany)->assertForbidden();
        $this->getJson('/api/modules/management-aset/v1/perencanaan-aset/organizations?kind=operating_units&legal_entity_id='.$otherCompany)->assertForbidden();
    }

    public function test_explicit_creation_in_another_allowed_company_does_not_change_normal_read_scope(): void
    {
        $otherCompany = (string) Str::ulid();
        $otherUnit = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $otherCompany, 'legal_entity');
        $this->pastikanOrganisasiAda($this->tenantId, $otherUnit, 'operating_unit');
        $payload = $this->payload($this->jenis($this->tenantId));
        $payload['legal_entity_id'] = $otherCompany;
        $payload['planning_org_unit_id'] = $otherUnit;
        $payload['planning_year_id'] = app(PlanningYearDirectory::class)->importLegacy($this->tenantId, $otherCompany, 2026)['id'];
        $this->sebagaiPenggunaBernama('planning-author', $this->tenantId, ['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.create'], [
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->orgUnitId],
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $otherCompany, 'organization_id' => $otherUnit],
        ]);
        $this->selectCompany($this->tenantId, $this->legalEntityId, $this->orgUnitId);
        $created = $this->withHeader('Idempotency-Key', 'allowed-company-choice')->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)
            ->assertCreated()->assertJsonPath('data.legal_entity_id', $otherCompany)->json('data');
        $this->getJson('/api/modules/management-aset/v1/perencanaan-aset/'.$created['id'])->assertNotFound();
        $this->postJson('/api/modules/management-aset/v1/perencanaan-aset', $payload)->assertOk()->assertJsonPath('data.id', $created['id']);
        $this->selectCompany($this->tenantId, $otherCompany, $otherUnit);
        $this->getJson('/api/modules/management-aset/v1/perencanaan-aset/'.$created['id'])->assertOk();
    }

    public function test_company_of_saved_document_cannot_be_changed(): void
    {
        $type = $this->jenis($this->tenantId);
        $plan = $this->create($type)->assertCreated()->json('data');
        $otherCompany = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $otherCompany, 'legal_entity');
        $payload = $this->payload($type);
        $payload['legal_entity_id'] = $otherCompany;
        $payload['planning_year_id'] = app(PlanningYearDirectory::class)->importLegacy($this->tenantId, $otherCompany, 2026)['id'];
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update'])
            ->patchJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], [...$payload, 'version' => $plan['version']])
            ->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
        $this->assertDatabaseHas('aset_tr_perencanaan_aset', ['id' => $plan['id'], 'legal_entity_id' => $this->legalEntityId]);
    }

    /** @return TestResponse<Response> */
    private function create(string $jenis): TestResponse
    {
        return $this->headers(['management-aset.perencanaan-aset.create'])
            ->withHeader('Idempotency-Key', 'plan-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $this->payload($jenis));
    }

    /** @param  list<string>  $permissions */
    private function headers(array $permissions): static
    {
        return $this->sebagaiPenggunaBernama('planning-author', $this->tenantId, $permissions);
    }

    /** @return array<string, mixed> */
    private function payload(string $jenis): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId, 'planning_org_unit_id' => $this->orgUnitId,
            'planned_on' => '2026-07-30', 'planning_year_id' => $this->planningYear(2026),
            'responsible_user_id' => $this->responsibleUserId, 'description' => 'Perangkat tim pengembangan',
            'details' => [[
                'jenis_aset_id' => $jenis, 'satuan_id' => $this->unitId, 'quantity' => 2,
                'requested_specification' => 'RAM 16 GB, SSD 512 GB', 'estimated_unit_price' => 15000000,
                'needed_on' => '2026-10-01', 'funding_source_id' => $this->fundingSourceId,
            ]],
        ];
    }

    private function planningYear(int $year): string
    {
        return app(PlanningYearDirectory::class)->importLegacy($this->tenantId, $this->legalEntityId, $year)['id'];
    }

    private function fundingSource(string $tenantId, bool $active = true): string
    {
        $id = (string) Str::ulid();
        DB::table('funding_sources')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'code' => 'F'.Str::random(8),
            'name' => 'Sumber uji '.Str::random(6), 'active' => $active, 'version' => 1,
            'creation_key' => 'fund-'.Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Jenis aset kini datar, jadi cukup satu insert tanpa group dan kategori di atasnya. */
    private function jenis(string $tenant): string
    {
        $type = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $tenant, 'creation_key' => 'type-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Laptop kerja', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return $type;
    }
}
