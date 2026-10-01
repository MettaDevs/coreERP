<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\SeedsMaintenanceFixtures;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Counter aset dan pembacaannya (padanan *Counters* F&O Asset Management).
 *
 * Yang dijaga: total pemakaian dihitung dari angka meter dan melewati penggantian meter, angka tidak
 * boleh turun tanpa penanda penggantian, riwayat tidak disisipi atau diubah di tengah, counter hanya
 * berlaku untuk jenis aset yang dikaitkan, dan pembacaan mengikuti jangkauan organisasi asetnya.
 */
class AssetCounterTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase, SeedsMaintenanceFixtures;

    private const URL = '/api/modules/management-aset/v1/pembacaan-counter';

    private const ALL = [
        'management-aset.pembacaan-counter.read',
        'management-aset.pembacaan-counter.create',
        'management-aset.pembacaan-counter.archive',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->unitId = (string) Str::ulid();
    }

    public function test_counter_type_takes_its_unit_from_core(): void
    {
        $satuan = $this->buatSatuanUji($this->tenantId, 'JAM', 'Jam');
        $permissions = array_map(static fn (string $action): string => 'management-aset.jenis-counter.'.$action, ['read', 'create', 'update', 'archive']);

        $created = $this->sebagaiPengguna($this->tenantId, $permissions)
            ->withHeader('Idempotency-Key', 'counter-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/jenis-counter', ['nama' => 'Jam operasi', 'satuan_id' => $satuan])
            ->assertCreated()
            ->assertJsonPath('data.satuan', 'JAM');
        $this->assertMatchesRegularExpression('/^'.preg_quote($this->awalanNomor('management-aset.jenis-counter'), '/').'/', (string) $created->json('data.kode'));

        $this->sebagaiPengguna($this->tenantId, $permissions)
            ->withHeader('Idempotency-Key', 'counter-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/jenis-counter', ['nama' => 'Tanpa satuan Core', 'satuan_id' => (string) Str::ulid()])
            ->assertUnprocessable()->assertJsonValidationErrors('satuan_id');
    }

    public function test_total_accumulates_and_passes_through_a_meter_replacement(): void
    {
        [$aset, $counter] = $this->assetWithCounter();

        $this->record($aset, $counter, '2026-09-01 08:00:00', 100)->assertCreated()->assertJsonPath('data.nilai_total', '100.00');
        $this->record($aset, $counter, '2026-09-10 08:00:00', 250)->assertCreated()->assertJsonPath('data.nilai_total', '250.00');

        // Angka turun tanpa penanda penggantian ditolak dan tidak menyimpan apa pun.
        $this->record($aset, $counter, '2026-09-20 08:00:00', 200)->assertUnprocessable()->assertJsonValidationErrors('nilai');

        // Penggantian meter ala F&O: bacaan akhir meter lama, lalu meter baru berwaktu sama dengan
        // penanda reset. Meter baru tidak menambah total; bacaan sesudahnya menambah dari angka awalnya.
        $this->record($aset, $counter, '2026-09-20 08:00:00', 300)->assertCreated()->assertJsonPath('data.nilai_total', '300.00');
        $this->record($aset, $counter, '2026-09-20 08:00:00', 0, ['reset' => true])->assertCreated()->assertJsonPath('data.nilai_total', '300.00');
        $this->record($aset, $counter, '2026-09-25 08:00:00', 40)->assertCreated()->assertJsonPath('data.nilai_total', '340.00');

        $this->assertSame(5, DB::table('aset_tr_pembacaan_counter')->whereNull('deleted_at')->count());
        $list = $this->as(self::ALL)->getJson(self::URL.'?aset_id='.$aset)->assertOk()->json('data');
        $this->assertSame(['340.00', '300.00', '300.00', '250.00', '100.00'], array_column($list, 'nilai_total'));
        $this->assertNotNull($list[0]['dicatat_oleh_nama'] ?? null, 'Pencatat ditampilkan dengan namanya.');
    }

    public function test_history_is_never_back_dated_and_only_the_latest_reading_can_be_archived(): void
    {
        [$aset, $counter] = $this->assetWithCounter();
        $first = (string) $this->record($aset, $counter, '2026-09-10 08:00:00', 100)->json('data.id');
        $this->record($aset, $counter, '2026-09-05 08:00:00', 120)->assertUnprocessable()->assertJsonValidationErrors('dibaca_pada');
        $last = (string) $this->record($aset, $counter, '2026-09-12 08:00:00', 150)->json('data.id');

        $this->as(self::ALL)->deleteJson(self::URL.'/'.$first, ['version' => 1])->assertUnprocessable();
        $this->as(self::ALL)->deleteJson(self::URL.'/'.$last)->assertStatus(428);
        $this->as(self::ALL)->deleteJson(self::URL.'/'.$last, ['version' => 1])->assertNoContent();
        $this->assertNotNull(DB::table('aset_tr_pembacaan_counter')->where('id', $last)->value('deleted_at'));

        // Sesudah yang terakhir diarsipkan, pembacaan berikutnya dihitung dari yang sebelumnya.
        $this->record($aset, $counter, '2026-09-12 09:00:00', 130)->assertCreated()->assertJsonPath('data.nilai_total', '130.00');
    }

    public function test_repeated_request_returns_the_same_reading(): void
    {
        [$aset, $counter] = $this->assetWithCounter();
        $key = 'baca-'.Str::ulid();

        $this->record($aset, $counter, '2026-09-10 08:00:00', 100, [], $key)->assertCreated();
        $this->record($aset, $counter, '2026-09-10 08:00:00', 100, [], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('aset_tr_pembacaan_counter')->count());
    }

    public function test_counter_linked_to_asset_types_only_applies_to_those_types(): void
    {
        $ventilator = $this->seedMaster('aset_m_jenis_aset', 'Ventilator');
        $kendaraan = $this->seedMaster('aset_m_jenis_aset', 'Kendaraan');
        $counter = $this->seedCounterType('Jam operasi');
        $jenisPermissions = ['management-aset.jenis-aset.read', 'management-aset.jenis-aset.update'];

        $this->sebagaiPengguna($this->tenantId, $jenisPermissions)
            ->putJson('/api/modules/management-aset/v1/jenis-aset/'.$ventilator.'/counter', ['jenis_counter_ids' => [$counter], 'version' => 1])
            ->assertOk()->assertJsonPath('data.selected.0.id', $counter);
        // Versi basi ditolak: rincian ini mengklaim versi jenis aset.
        $this->sebagaiPengguna($this->tenantId, $jenisPermissions)
            ->putJson('/api/modules/management-aset/v1/jenis-aset/'.$ventilator.'/counter', ['jenis_counter_ids' => [], 'version' => 1])
            ->assertConflict();

        $mobil = $this->seedAsset($kendaraan, 'AST-MBL');
        $alat = $this->seedAsset($ventilator, 'AST-VNT');
        $this->record($mobil, $counter, '2026-09-10 08:00:00', 10)->assertUnprocessable()->assertJsonValidationErrors('jenis_counter_id');
        $this->record($alat, $counter, '2026-09-10 08:00:00', 10)->assertCreated();

        $this->as(self::ALL)->getJson(self::URL.'/counter-aset?aset_id='.$alat)->assertOk()
            ->assertJsonPath('data.0.jenis_counter_id', $counter)
            ->assertJsonPath('data.0.terakhir.nilai_total', '10.00');
        $this->as(self::ALL)->getJson(self::URL.'/counter-aset?aset_id='.$mobil)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_readings_follow_the_organisation_scope_of_the_asset(): void
    {
        [$aset, $counter] = $this->assetWithCounter();
        $this->record($aset, $counter, '2026-09-10 08:00:00', 100)->assertCreated();
        $otherUnit = [['policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => (string) Str::ulid()]];

        $this->sebagaiPengguna($this->tenantId, self::ALL, $otherUnit)->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->sebagaiPengguna($this->tenantId, self::ALL, $otherUnit)
            ->withHeader('Idempotency-Key', 'baca-'.Str::ulid())
            ->postJson(self::URL, ['aset_id' => $aset, 'jenis_counter_id' => $counter, 'dibaca_pada' => '2026-09-11 08:00:00', 'nilai' => 120])
            ->assertUnprocessable()->assertJsonValidationErrors('aset_id');
        $this->sebagaiPengguna($this->tenantId, ['management-aset.pembacaan-counter.read'])
            ->withHeader('Idempotency-Key', 'baca-'.Str::ulid())
            ->postJson(self::URL, ['aset_id' => $aset, 'jenis_counter_id' => $counter, 'dibaca_pada' => '2026-09-11 08:00:00', 'nilai' => 120])
            ->assertForbidden();
    }

    /** @return array{0: string, 1: string} */
    private function assetWithCounter(): array
    {
        return [$this->seedAsset($this->seedMaster('aset_m_jenis_aset', 'Ventilator'), 'AST-'.Str::random(5)), $this->seedCounterType()];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function record(string $aset, string $counter, string $at, int|float $value, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->as(self::ALL)
            ->withHeader('Idempotency-Key', $key ?? 'baca-'.Str::ulid())
            ->postJson(self::URL, ['aset_id' => $aset, 'jenis_counter_id' => $counter, 'dibaca_pada' => $at, 'nilai' => $value, ...$extra]);
    }

    /** @param  list<string>  $permissions */
    private function as(array $permissions): static
    {
        return $this->sebagaiPenggunaBernama('pencatat', $this->tenantId, $permissions);
    }
}
