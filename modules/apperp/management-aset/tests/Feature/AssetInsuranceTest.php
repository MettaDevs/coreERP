<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\SeedsMaintenanceFixtures;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Asuransi aset (padanan *Insurance* Business Central).
 *
 * Yang dijaga: pertanggungan adalah riwayat, bukan setelan yang ditimpa; satu aset tidak ditanggung
 * dua kali oleh polis yang sama pada tanggal yang sama; total yang ditanggung dihitung pada satu tanggal;
 * aset tidak diasuransikan dan kurang diasuransikan terbaca dari perbandingan dengan nilai perolehan;
 * dan polis mengikuti jangkauan entitas legal.
 */
class AssetInsuranceTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase, SeedsMaintenanceFixtures;

    private const URL = '/api/modules/management-aset/v1/polis-asuransi';

    private const ASSET_URL = '/api/modules/management-aset/v1/asuransi-aset';

    private const MANAGER = [
        'management-aset.polis-asuransi.read', 'management-aset.polis-asuransi.create',
        'management-aset.polis-asuransi.update', 'management-aset.polis-asuransi.archive',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    private string $jenisAset;

    private string $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->unitId = (string) Str::ulid();
        $this->jenisAset = $this->seedMaster('aset_m_jenis_aset', 'Kendaraan');
        $this->vendorId = $this->pastikanVendorUji($this->tenantId, $this->legalEntityId, 'PT Asuransi Uji');
    }

    public function test_policy_gets_a_number_and_its_insurer_must_be_a_vendor_of_its_legal_entity(): void
    {
        $created = $this->createPolicy()->assertCreated()
            ->assertJsonPath('data.vendor.name', 'PT Asuransi Uji')
            ->assertJsonPath('data.total_nilai_tertanggung', '0.00')
            ->assertJsonPath('data.berlaku', true);
        $this->assertMatchesRegularExpression('/^'.preg_quote($this->awalanNomor('management-aset.polis-asuransi'), '/').'/', (string) $created->json('data.kode'));

        $otherLegalEntity = (string) Str::ulid();
        $foreignVendor = $this->pastikanVendorUji($this->tenantId, $otherLegalEntity, 'PT Asuransi Lain');
        $this->createPolicy(['vendor_id' => $foreignVendor])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
    }

    public function test_coverage_is_history_and_total_insured_is_read_on_a_date(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'KND-01');
        $policy = (string) $this->createPolicy()->json('data.id');

        $this->addCoverage($policy, ['aset_id' => $aset, 'nilai_pertanggungan' => 30000000, 'berlaku_mulai' => '2026-01-01'])->assertCreated()
            ->assertJsonPath('data.total_nilai_tertanggung', '30000000.00')
            ->assertJsonCount(1, 'data.pertanggungan');
        // Polis yang sama tidak boleh menanggung aset yang sama dua kali pada tanggal yang sama.
        $this->addCoverage($policy, ['aset_id' => $aset, 'nilai_pertanggungan' => 1000, 'berlaku_mulai' => '2026-03-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('aset_id');

        $coverage = (string) DB::table('aset_tr_pertanggungan_asuransi')->where('polis_asuransi_id', $policy)->value('id');
        $this->as(self::MANAGER)->postJson(self::URL.'/'.$policy.'/pertanggungan/'.$coverage.'/ganti-nilai', [
            'nilai_pertanggungan' => 45000000, 'berlaku_mulai' => '2026-06-01', 'version' => $this->version($policy),
        ])->assertOk()->assertJsonPath('data.total_nilai_tertanggung', '45000000.00')->assertJsonCount(2, 'data.pertanggungan');

        // Nilai lama tidak ditimpa: ia berakhir sehari sebelum nilai baru berlaku.
        $this->assertDatabaseHas('aset_tr_pertanggungan_asuransi', ['id' => $coverage, 'nilai_pertanggungan' => 30000000, 'berlaku_sampai' => '2026-05-31']);
        $this->assertDatabaseHas('aset_tr_pertanggungan_asuransi', ['polis_asuransi_id' => $policy, 'nilai_pertanggungan' => 45000000, 'berlaku_mulai' => '2026-06-01', 'berlaku_sampai' => null]);

        $history = $this->as(self::MANAGER)->getJson(self::ASSET_URL.'?aset_id='.$aset)->assertOk();
        $history->assertJsonPath('data.total_nilai_tertanggung', '45000000.00')
            ->assertJsonPath('data.status', 'kurang_diasuransikan')
            ->assertJsonCount(2, 'data.pertanggungan');
    }

    public function test_summary_lists_uninsured_and_underinsured_assets_against_acquisition_value(): void
    {
        $uninsured = $this->seedAsset($this->jenisAset, 'KND-A');
        $under = $this->seedAsset($this->jenisAset, 'KND-B');
        $insured = $this->seedAsset($this->jenisAset, 'KND-C');
        $this->seedAsset($this->jenisAset, 'KND-X', ['lifecycle_state' => 'disposed']);
        $policy = (string) $this->createPolicy()->json('data.id');
        $this->addCoverage($policy, ['aset_id' => $under, 'nilai_pertanggungan' => 20000000, 'berlaku_mulai' => '2026-01-01'])->assertCreated();
        $this->addCoverage($policy, ['aset_id' => $insured, 'nilai_pertanggungan' => 50000000, 'berlaku_mulai' => '2026-01-01'])->assertCreated();

        $problems = $this->as(self::MANAGER)->getJson(self::ASSET_URL.'/ringkasan')->assertOk();
        $rows = (array) $problems->json('data');
        $this->assertSame([$uninsured => 'tidak_diasuransikan', $under => 'kurang_diasuransikan'], array_column($rows, 'status', 'id'));
        $this->assertSame('30000000.00', array_column($rows, 'kekurangan', 'id')[$under]);

        // Sebelum polis berlaku, tidak ada yang ditanggung.
        $before = $this->as(self::MANAGER)->getJson(self::ASSET_URL.'/ringkasan?tanggal=2025-12-31&status=tidak_diasuransikan')->assertOk();
        $this->assertCount(3, $before->json('data'));
    }

    public function test_blocked_policy_and_assets_outside_the_policy_are_rejected(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'KND-D');
        $foreign = $this->seedAsset($this->jenisAset, 'KND-E', ['legal_entity_id' => (string) Str::ulid()]);
        $policy = (string) $this->createPolicy(['berlaku_sampai' => '2026-12-31'])->json('data.id');

        $this->addCoverage($policy, ['aset_id' => $foreign, 'nilai_pertanggungan' => 1000, 'berlaku_mulai' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('aset_id');
        $this->addCoverage($policy, ['aset_id' => $aset, 'nilai_pertanggungan' => 1000, 'berlaku_mulai' => '2025-12-01'])->assertUnprocessable()->assertJsonValidationErrors('berlaku_mulai');
        $this->addCoverage($policy, ['aset_id' => $aset, 'nilai_pertanggungan' => 1000, 'berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2027-06-30'])->assertUnprocessable()->assertJsonValidationErrors('berlaku_sampai');

        $this->as(self::MANAGER)->patchJson(self::URL.'/'.$policy, [...$this->policyPayload(['diblokir' => true, 'berlaku_sampai' => '2026-12-31']), 'version' => $this->version($policy)])->assertOk();
        $this->addCoverage($policy, ['aset_id' => $aset, 'nilai_pertanggungan' => 1000, 'berlaku_mulai' => '2026-01-01'])->assertUnprocessable();
    }

    public function test_policy_with_running_coverage_cannot_be_archived_and_writes_need_the_version(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'KND-F');
        $policy = (string) $this->createPolicy()->json('data.id');
        $this->as(self::MANAGER)->withHeader('Idempotency-Key', 'cov-'.Str::ulid())
            ->postJson(self::URL.'/'.$policy.'/pertanggungan', ['aset_id' => $aset, 'nilai_pertanggungan' => 1000, 'berlaku_mulai' => '2026-01-01'])
            ->assertStatus(428);
        $this->addCoverage($policy, ['aset_id' => $aset, 'nilai_pertanggungan' => 1000, 'berlaku_mulai' => '2026-01-01'])->assertCreated();

        $this->as(self::MANAGER)->deleteJson(self::URL.'/'.$policy, ['version' => $this->version($policy)])->assertUnprocessable();
        $coverage = (string) DB::table('aset_tr_pertanggungan_asuransi')->where('polis_asuransi_id', $policy)->value('id');
        $this->as(self::MANAGER)->postJson(self::URL.'/'.$policy.'/pertanggungan/'.$coverage.'/akhiri', ['berlaku_sampai' => '2026-02-01', 'version' => $this->version($policy) - 1])->assertConflict();
        $this->as(self::MANAGER)->postJson(self::URL.'/'.$policy.'/pertanggungan/'.$coverage.'/akhiri', ['berlaku_sampai' => '2026-02-01', 'version' => $this->version($policy)])->assertOk();
        $this->as(self::MANAGER)->deleteJson(self::URL.'/'.$policy, ['version' => $this->version($policy)])->assertNoContent();
        $this->as(self::MANAGER)->getJson(self::URL.'/'.$policy)->assertNotFound();
        $this->assertNotNull(DB::table('aset_m_polis_asuransi')->where('id', $policy)->value('deleted_at'), 'Arsip, bukan hapus.');
    }

    public function test_policies_follow_the_legal_entity_scope_and_create_is_idempotent(): void
    {
        $key = 'polis-'.Str::ulid();
        $id = (string) $this->createPolicy([], $key)->assertCreated()->json('data.id');
        $this->createPolicy([], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('aset_m_polis_asuransi')->count());

        $other = fn () => $this->sebagaiPenggunaBernama('Entitas lain', $this->tenantId, self::MANAGER, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => (string) Str::ulid(), 'organization_id' => (string) Str::ulid(),
        ]]);
        $other()->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $other()->getJson(self::URL.'/'.$id)->assertNotFound();
        // Polis tidak berunit: hibah pada entitas legalnya, unit mana pun, sudah cukup.
        $this->sebagaiPenggunaBernama('Unit sendiri', $this->tenantId, self::MANAGER, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->unitId,
        ]])->getJson(self::URL.'/'.$id)->assertOk();
        $other()->withHeader('Idempotency-Key', 'polis-'.Str::ulid())->postJson(self::URL, $this->policyPayload([]))->assertForbidden();
    }

    public function test_insurance_types_are_a_master_and_the_real_duty_grants_the_screen(): void
    {
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']), Artisan::output());
        $this->assertDatabaseHas('permissions', ['code' => 'management-aset.polis-asuransi.read']);
        $this->assertDatabaseHas('security_duties', ['code' => 'management-aset.polis-asuransi.manage']);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.jenis-asuransi.read', 'management-aset.jenis-asuransi.create'])
            ->withHeader('Idempotency-Key', 'jenis-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/jenis-asuransi', ['nama' => 'Kendaraan bermotor'])
            ->assertCreated()->assertJsonPath('data.nama', 'Kendaraan bermotor');
        $this->assertNotNull(app(AttachmentRecordTypes::class)->for('aset_m_polis_asuransi'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function policyPayload(array $overrides): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId,
            'nama' => 'Polis kendaraan operasional',
            'nomor_polis' => 'PLS/2026/0001',
            'vendor_id' => $this->vendorId,
            'berlaku_mulai' => '2026-01-01',
            'premi_tahunan' => 12000000,
            'nilai_pertanggungan' => 500000000,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function createPolicy(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->as(self::MANAGER)->withHeader('Idempotency-Key', $key ?? 'polis-'.Str::ulid())->postJson(self::URL, $this->policyPayload($overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function addCoverage(string $policy, array $payload): TestResponse
    {
        return $this->as(self::MANAGER)->withHeader('Idempotency-Key', 'cov-'.Str::ulid())
            ->postJson(self::URL.'/'.$policy.'/pertanggungan', [...$payload, 'version' => $this->version($policy)]);
    }

    private function version(string $id): int
    {
        return (int) DB::table('aset_m_polis_asuransi')->where('id', $id)->value('version');
    }

    /** @param  list<string>  $permissions */
    private function as(array $permissions): static
    {
        return $this->sebagaiPenggunaBernama('pengguna', $this->tenantId, $permissions);
    }
}
