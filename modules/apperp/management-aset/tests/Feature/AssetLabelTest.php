<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\AssetLabelController;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

/**
 * Lembar label aset berkode QR: hak, cakupan unit kerja, batas tenant, dan batas jumlahnya sama
 * dengan register aset yang menjadi sumbernya.
 */
class AssetLabelTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const URL = '/management-aset/label-aset';

    private string $tenantId;

    private string $legalEntityId;

    /** @var array{group_aset_id: string, jenis_aset_id: string} */
    private array $classification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->classification = $this->classification($this->tenantId);
    }

    public function test_sheet_prints_the_chosen_assets_with_qr_code_name_and_location(): void
    {
        $unit = (string) Str::ulid();
        $location = $this->location('Gudang Cakung');
        $laptop = $this->asset($this->tenantId, 'AST-L1', 'Laptop <b>kantor</b>', $unit, $location);
        $this->asset($this->tenantId, 'AST-L2', 'Printer', $unit);

        $response = $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'])
            ->get(self::URL.'?ids='.$laptop)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('AST-L1', $html);
        $this->assertStringContainsString('Gudang Cakung', $html);
        // Nama aset ditulis sebagai teks, bukan HTML.
        $this->assertStringContainsString('Laptop &lt;b&gt;kantor&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('AST-L2', $html);
        $this->assertSame(1, substr_count($html, '<svg'));
        $this->assertStringContainsString('1 label di 1 lembar A4', $html);
    }

    public function test_search_prints_what_the_register_shows_and_never_crosses_the_unit_or_tenant(): void
    {
        $unitA = (string) Str::ulid();
        $unitB = (string) Str::ulid();
        $this->asset($this->tenantId, 'AST-A1', 'Laptop unit A', $unitA);
        $this->asset($this->tenantId, 'AST-A2', 'Printer unit A', $unitA);
        $outsideUnit = $this->asset($this->tenantId, 'AST-B1', 'Laptop unit B', $unitB);

        $otherTenant = $this->buatTenantUji();
        $otherClassification = $this->classification($otherTenant);
        $foreign = $this->asset($otherTenant, 'AST-X1', 'Laptop tenant lain', $unitA, null, $otherClassification);

        $scope = [['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $unitA]];
        $user = $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'], $scope);

        $html = (string) $user->get(self::URL.'?q=laptop')->assertOk()->getContent();
        $this->assertStringContainsString('AST-A1', $html);
        $this->assertStringNotContainsString('AST-A2', $html);
        $this->assertStringNotContainsString('AST-B1', $html);
        $this->assertStringNotContainsString('AST-X1', $html);

        // Id aset di luar unit kerja atau milik tenant lain tidak dicetak dan tidak disebut.
        $user->get(self::URL.'?ids='.$outsideUnit.','.$foreign)
            ->assertNotFound()
            ->assertDontSee('AST-B1')
            ->assertDontSee('AST-X1');
    }

    public function test_ids_without_a_valid_id_never_fall_back_to_every_asset(): void
    {
        $this->asset($this->tenantId, 'AST-V1', 'Laptop', (string) Str::ulid());

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'])
            ->get(self::URL.'?ids=bukan-id')
            ->assertNotFound()
            ->assertDontSee('AST-V1');
    }

    public function test_more_labels_than_the_limit_are_refused_with_a_reason(): void
    {
        $unit = (string) Str::ulid();
        $now = now();
        $rows = [];
        for ($i = 1; $i <= AssetLabelController::MAX_LABELS + 1; $i++) {
            $rows[] = $this->assetRow($this->tenantId, sprintf('AST-M%04d', $i), 'Kursi', $unit, null, $this->classification) + ['created_at' => $now, 'updated_at' => $now];
        }
        DB::table('aset_tr_aset')->insert($rows);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'])
            ->get(self::URL)
            ->assertStatus(422)
            ->assertSee('Paling banyak '.AssetLabelController::MAX_LABELS.' label sekali cetak');
    }

    public function test_without_register_read_permission_the_sheet_is_closed(): void
    {
        $id = $this->asset($this->tenantId, 'AST-P1', 'Laptop', (string) Str::ulid());

        $this->sebagaiPengguna($this->tenantId, ['management-aset.pemeliharaan-aset.read'])
            ->get(self::URL.'?ids='.$id)
            ->assertForbidden();
    }

    /** @param array{group_aset_id: string, jenis_aset_id: string}|null $classification */
    private function asset(string $tenantId, string $kode, string $nama, string $unit, ?string $location = null, ?array $classification = null): string
    {
        $row = $this->assetRow($tenantId, $kode, $nama, $unit, $location, $classification ?? $this->classification);
        DB::table('aset_tr_aset')->insert($row + ['created_at' => now(), 'updated_at' => now()]);

        return $row['id'];
    }

    /**
     * @param  array{group_aset_id: string, jenis_aset_id: string}  $classification
     * @return array<string, mixed>
     */
    private function assetRow(string $tenantId, string $kode, string $nama, string $unit, ?string $location, array $classification): array
    {
        return [
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'nama' => $nama, 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $unit,
            ...$classification, 'lokasi_aset_id' => $location, 'acquired_on' => '2026-01-01',
            'acquisition_value' => 1000000, 'currency_code' => 'IDR',
        ];
    }

    private function location(string $nama): string
    {
        $type = (string) Str::ulid();
        DB::table('aset_m_tipe_lokasi_aset')->insert([
            'id' => $type, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'TLKA-'.Str::random(5), 'nama' => 'Gudang', 'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (string) Str::ulid();
        DB::table('aset_m_lokasi_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'LOCA-'.Str::random(5), 'nama' => $nama, 'tipe_lokasi_id' => $type, 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{group_aset_id: string, jenis_aset_id: string} */
    private function classification(string $tenantId): array
    {
        $group = (string) Str::ulid();
        $type = (string) Str::ulid();
        DB::table('aset_m_group_aset')->insert(['id' => $group, 'tenant_id' => $tenantId, 'creation_key' => 'g-'.Str::ulid(), 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('aset_m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $tenantId, 'creation_key' => 'j-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => now(), 'updated_at' => now()]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $type];
    }
}
