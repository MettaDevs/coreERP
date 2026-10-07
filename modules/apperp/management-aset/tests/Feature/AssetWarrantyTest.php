<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\SeedsMaintenanceFixtures;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Garansi aset dan kontrak servis vendor (padanan *Vendor warranty* F&O).
 *
 * Yang dijaga: garansi mengikuti jangkauan asetnya, daftar akan berakhir memilah 30/60/90 hari, kontrak
 * servis menanggung banyak aset dan tidak menyentuh aset di luar jangkauan penyimpannya, dan work order
 * mendapat pemberitahuan garansi aktif tanpa diblokir.
 */
class AssetWarrantyTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase, SeedsMaintenanceFixtures;

    private const URL = '/api/modules/management-aset/v1/garansi-aset';

    private const CONTRACT_URL = '/api/modules/management-aset/v1/kontrak-servis';

    private const MANAGER = [
        'management-aset.garansi-aset.read', 'management-aset.garansi-aset.create', 'management-aset.garansi-aset.update',
        'management-aset.garansi-aset.archive', 'management-aset.kontrak-servis.read', 'management-aset.kontrak-servis.create',
        'management-aset.kontrak-servis.update', 'management-aset.kontrak-servis.archive',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    private string $jenisAset;

    private string $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        // Endpoint menghitung "hari ini" menurut zona pengguna, sedangkan `day()` memakai tanggal UTC. Antara
        // pukul 17.00 dan 24.00 UTC keduanya berbeda satu hari dan sisa hari meleset satu. Jam 05.00 UTC
        // jatuh di tanggal yang sama untuk WIB, WITA, dan WIT.
        $this->travelTo(now('UTC')->startOfDay()->addHours(5));
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->unitId = (string) Str::ulid();
        $this->jenisAset = $this->seedMaster('aset_m_jenis_aset', 'Alat kesehatan');
        $this->vendorId = $this->pastikanVendorUji($this->tenantId, $this->legalEntityId, 'PT Servis Alat');
    }

    public function test_warranty_is_recorded_per_asset_and_edits_need_the_version(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'ALK-01');
        $created = $this->warranty($aset, $this->day(-30), $this->day(200))->assertCreated()
            ->assertJsonPath('data.vendor.name', 'PT Servis Alat')
            ->assertJsonPath('data.jenis_garansi_label', 'Penuh')
            ->assertJsonPath('data.berlaku', true);
        $id = (string) $created->json('data.id');

        $this->as(self::MANAGER)->patchJson(self::URL.'/'.$id, $this->payload($aset, $this->day(-30), $this->day(400), false))->assertStatus(428);
        $this->as(self::MANAGER)->patchJson(self::URL.'/'.$id, [...$this->payload($aset, $this->day(-30), $this->day(400), false), 'jenis_garansi' => 'sebagian', 'version' => $this->version($id)])
            ->assertOk()->assertJsonPath('data.jenis_garansi', 'sebagian')->assertJsonPath('data.berlaku_sampai', $this->day(400));
        $this->as(self::MANAGER)->getJson(self::URL.'?aset_id='.$aset)->assertOk()->assertJsonCount(1, 'data');

        $this->as(self::MANAGER)->deleteJson(self::URL.'/'.$id, ['version' => $this->version($id)])->assertNoContent();
        $this->as(self::MANAGER)->getJson(self::URL.'?aset_id='.$aset)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_expiring_list_separates_30_60_and_90_days_for_warranties_and_contracts(): void
    {
        $a = $this->seedAsset($this->jenisAset, 'ALK-A');
        $b = $this->seedAsset($this->jenisAset, 'ALK-B');
        $this->warranty($a, $this->day(-100), $this->day(10))->assertCreated();
        $this->warranty($b, $this->day(-100), $this->day(45))->assertCreated();
        $this->warranty($b, $this->day(-400), $this->day(-1))->assertCreated();
        $this->contract([$a, $b], $this->day(-200), $this->day(80))->assertCreated()->assertJsonPath('data.lines_count', 2);

        $thirty = $this->as(self::MANAGER)->getJson(self::URL.'/akan-berakhir')->assertOk();
        $this->assertSame(['garansi'], array_column((array) $thirty->json('data'), 'jenis'));
        $this->assertSame(10, $thirty->json('data.0.sisa_hari'));
        $this->assertCount(2, $this->as(self::MANAGER)->getJson(self::URL.'/akan-berakhir?hari=60')->json('data'));
        $ninety = $this->as(self::MANAGER)->getJson(self::URL.'/akan-berakhir?hari=90')->assertOk();
        $this->assertSame(['garansi', 'garansi', 'kontrak_servis'], array_column((array) $ninety->json('data'), 'jenis'));
        $this->as(self::MANAGER)->getJson(self::URL.'/akan-berakhir?hari=45')->assertUnprocessable();

        // Tanpa hak kontrak servis, yang terlihat hanya garansi.
        $onlyWarranty = $this->as(['management-aset.garansi-aset.read'])->getJson(self::URL.'/akan-berakhir?hari=90')->assertOk();
        $this->assertSame(['garansi', 'garansi'], array_column((array) $onlyWarranty->json('data'), 'jenis'));
    }

    public function test_work_order_sees_active_warranty_and_contract_as_information(): void
    {
        $covered = $this->seedAsset($this->jenisAset, 'ALK-C');
        $bare = $this->seedAsset($this->jenisAset, 'ALK-D');
        $this->warranty($covered, $this->day(-10), $this->day(100))->assertCreated();
        $this->contract([$covered], $this->day(-10), $this->day(50))->assertCreated();

        $notice = $this->as(['management-aset.pemeliharaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/pemeliharaan-aset/referensi/garansi?aset_id[]='.$covered.'&aset_id[]='.$bare)
            ->assertOk();
        $this->assertSame(['garansi', 'kontrak_servis'], array_column((array) $notice->json('data'), 'jenis'));
        $this->assertSame([$covered], array_values(array_unique(array_column((array) $notice->json('data'), 'aset_id'))));

        // Pada tanggal sesudah kontrak berakhir, yang tersisa hanya garansi.
        $later = $this->as(['management-aset.pemeliharaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/pemeliharaan-aset/referensi/garansi?aset_id[]='.$covered.'&tanggal='.$this->day(70))
            ->assertOk();
        $this->assertSame(['garansi'], array_column((array) $later->json('data'), 'jenis'));
        $this->as(['management-aset.garansi-aset.read'])->getJson('/api/modules/management-aset/v1/pemeliharaan-aset/referensi/garansi?aset_id[]='.$covered)->assertForbidden();
    }

    public function test_contract_sync_keeps_assets_outside_the_users_scope(): void
    {
        $mine = $this->seedAsset($this->jenisAset, 'ALK-E');
        $otherUnit = (string) Str::ulid();
        $theirs = $this->seedAsset($this->jenisAset, 'ALK-F', ['responsible_org_unit_id' => $otherUnit]);
        $id = (string) $this->contract([$mine, $theirs], $this->day(-10), $this->day(300))->assertCreated()->json('data.id');

        $scoped = fn () => $this->sebagaiPenggunaBernama('Unit sendiri', $this->tenantId, self::MANAGER, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->unitId,
        ]]);
        $scoped()->getJson(self::CONTRACT_URL.'/'.$id)->assertOk()->assertJsonCount(1, 'data.aset')->assertJsonPath('data.lines_count', 2);
        // Detail aset membaca kontrak yang menanggungnya.
        $this->as(self::MANAGER)->getJson(self::CONTRACT_URL.'?aset_id='.$theirs)->assertOk()->assertJsonCount(1, 'data');
        $this->as(self::MANAGER)->getJson(self::CONTRACT_URL.'?aset_id='.$this->seedAsset($this->jenisAset, 'ALK-H'))->assertOk()->assertJsonCount(0, 'data');
        // Menyimpan tanpa aset miliknya sendiri tidak mengeluarkan aset unit lain yang tidak ia lihat.
        $scoped()->patchJson(self::CONTRACT_URL.'/'.$id, [...$this->contractPayload([], $this->day(-10), $this->day(300)), 'version' => $this->contractVersion($id)])->assertOk();
        $this->assertSame([$theirs], DB::table('aset_tr_kontrak_servis_aset')->where('kontrak_servis_id', $id)->whereNull('deleted_at')->pluck('aset_id')->all());
        // Aset unit lain juga tidak dapat ditambahkannya.
        $scoped()->patchJson(self::CONTRACT_URL.'/'.$id, [...$this->contractPayload([$theirs, $mine], $this->day(-10), $this->day(300)), 'version' => $this->contractVersion($id)])->assertOk();
        $scoped()->patchJson(self::CONTRACT_URL.'/'.$id, [...$this->contractPayload([(string) Str::ulid()], $this->day(-10), $this->day(300)), 'version' => $this->contractVersion($id)])
            ->assertUnprocessable()->assertJsonValidationErrors('aset_ids');
        $this->assertNotNull(app(AttachmentRecordTypes::class)->for('aset_tr_kontrak_servis'));
    }

    public function test_warranty_follows_the_asset_scope(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'ALK-G');
        $this->warranty($aset, $this->day(-1), $this->day(10))->assertCreated();
        $other = fn () => $this->sebagaiPenggunaBernama('Unit lain', $this->tenantId, self::MANAGER, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => (string) Str::ulid(),
        ]]);
        $other()->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $other()->withHeader('Idempotency-Key', 'garansi-'.Str::ulid())->postJson(self::URL, $this->payload($aset, $this->day(-1), $this->day(10), true))
            ->assertUnprocessable()->assertJsonValidationErrors('aset_id');
    }

    /** Tanggal relatif hari ini; pengguna test tanpa zona dan tanpa entitas legal memakai zona aplikasi. */
    private function day(int $offset): string
    {
        return now()->startOfDay()->addDays($offset)->toDateString();
    }

    /** @return array<string, mixed> */
    private function payload(string $aset, string $from, string $until, bool $creating): array
    {
        return array_filter([
            'aset_id' => $creating ? $aset : null,
            'vendor_id' => $this->vendorId,
            'jenis_garansi' => 'penuh',
            'nomor_referensi' => 'GRS-'.Str::random(5),
            'berlaku_mulai' => $from,
            'berlaku_sampai' => $until,
        ], static fn ($value) => $value !== null);
    }

    /** @return TestResponse<Response> */
    private function warranty(string $aset, string $from, string $until): TestResponse
    {
        return $this->as(self::MANAGER)->withHeader('Idempotency-Key', 'garansi-'.Str::ulid())->postJson(self::URL, $this->payload($aset, $from, $until, true));
    }

    /**
     * @param  list<string>  $assets
     * @return array<string, mixed>
     */
    private function contractPayload(array $assets, string $from, string $until): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId, 'nomor_kontrak' => 'KTR/2026/7', 'vendor_id' => $this->vendorId,
            'berlaku_mulai' => $from, 'berlaku_sampai' => $until, 'cakupan' => 'Kunjungan bulanan dan suku cadang', 'aset_ids' => $assets,
        ];
    }

    /**
     * @param  list<string>  $assets
     * @return TestResponse<Response>
     */
    private function contract(array $assets, string $from, string $until): TestResponse
    {
        return $this->as(self::MANAGER)->withHeader('Idempotency-Key', 'kontrak-'.Str::ulid())->postJson(self::CONTRACT_URL, $this->contractPayload($assets, $from, $until));
    }

    private function version(string $id): int
    {
        return (int) DB::table('aset_tr_garansi_aset')->where('id', $id)->value('version');
    }

    private function contractVersion(string $id): int
    {
        return (int) DB::table('aset_tr_kontrak_servis')->where('id', $id)->value('version');
    }

    /** @param  list<string>  $permissions */
    private function as(array $permissions): static
    {
        return $this->sebagaiPenggunaBernama('pengguna', $this->tenantId, $permissions);
    }
}
