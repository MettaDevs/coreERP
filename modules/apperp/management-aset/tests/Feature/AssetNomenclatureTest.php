<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Support\ModuleManifestFiles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Tests\TestCase;

class AssetNomenclatureTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    public function test_receipts_use_master_codes_acquisition_year_and_independent_counters(): void
    {
        $tenantId = $this->buatTenantUji();
        $manifest = ModuleManifestFiles::read(base_path('../../modules/apperp/management-aset'));
        /** @var list<array{code:string,default_settings:array{segments:list<array<string,mixed>>,maximum_number:int}}> $references */
        $references = $manifest['number_sequences']['references'];
        $settings = collect($references)->firstWhere('code', 'management-aset.asset-code')['default_settings'];
        $reference = DB::table('app_number_sequence_references')->where('code', 'management-aset.asset-code')->value('id');
        DB::table('tenant_number_sequences')->where('tenant_id', $tenantId)->where('reference_id', $reference)->update([
            'segments' => json_encode($settings['segments'], JSON_THROW_ON_ERROR), 'maximum_number' => $settings['maximum_number'],
        ]);
        $classificationId = (string) Str::ulid();
        DB::table('aset_m_kelompok_harta_fiskal')->insert([
            'id' => $classificationId, 'tenant_id' => $tenantId, 'template_key' => 'test:asset-code',
            'jurisdiction' => 'ID', 'kode' => '01', 'label' => 'Kelompok uji', 'effective_from' => '2020-01-01',
            'depreciable' => false, 'aktif' => true,
        ]);
        $groupId = (string) Str::ulid();
        DB::table('aset_m_group_aset')->insert([
            'id' => $groupId, 'tenant_id' => $tenantId, 'creation_key' => 'group:'.Str::ulid(),
            'kode' => '00A', 'nama' => 'Grup uji', 'kelompok_harta_fiskal_id' => $classificationId, 'aktif' => true,
        ]);
        $typeId = (string) Str::ulid();
        DB::table('aset_m_jenis_aset')->insert([
            'id' => $typeId, 'tenant_id' => $tenantId, 'creation_key' => 'type:'.Str::ulid(), 'kode' => 'TYPE', 'nama' => 'Jenis uji', 'aktif' => true,
        ]);
        $locations = [];
        foreach (['UGD', 'POLI'] as $code) {
            $locations[$code] = (string) Str::ulid();
            DB::table('aset_m_lokasi_aset')->insert([
                'id' => $locations[$code], 'tenant_id' => $tenantId, 'creation_key' => 'location:'.Str::ulid(), 'kode' => $code, 'nama' => 'Lokasi uji '.$code, 'aktif' => true,
            ]);
        }
        $entityId = (string) Str::ulid();
        $payload = ['legal_entity_id' => $entityId, 'usage_org_unit_id' => (string) Str::ulid(),
            'nama' => 'Sepeda motor uji', 'group_aset_id' => $groupId, 'jenis_aset_id' => $typeId,
            'lokasi_aset_id' => $locations['UGD'], 'acquired_on' => '2026-01-15', 'acquisition_value' => 1000000, 'currency_code' => 'IDR'];
        $first = $this->terimaAset($tenantId, $payload);
        $second = $this->terimaAset($tenantId, $payload);
        $otherLocation = $this->terimaAset($tenantId, [...$payload, 'lokasi_aset_id' => $locations['POLI']]);
        $otherYear = $this->terimaAset($tenantId, [...$payload, 'acquired_on' => '2025-01-15']);
        foreach ([$first => '01.00A.UGD.2026.0001', $second => '01.00A.UGD.2026.0002', $otherLocation => '01.00A.POLI.2026.0001', $otherYear => '01.00A.UGD.2025.0001'] as $id => $code) {
            $this->assertDatabaseHas('aset_tr_aset', ['id' => $id, 'kode' => $code]);
        }
        $mutation = $this->sebagaiPengguna($tenantId, ['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', 'mutation:'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', [
                'legal_entity_id' => $entityId, 'responsible_org_unit_id' => $payload['usage_org_unit_id'],
                'tujuan_org_unit_id' => $payload['usage_org_unit_id'], 'tujuan_lokasi_id' => $locations['POLI'],
                'tanggal' => '2026-02-01', 'alasan' => 'Uji kode tetap saat pindah lokasi', 'details' => [['aset_id' => $first]],
            ])->assertCreated()->json('data.id');
        $this->sebagaiPengguna($tenantId, ['management-aset.aset.mutate', 'management-aset.mutasi-aset.read'])
            ->postJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutation.'/selesaikan', ['version' => 1])->assertOk();
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $first, 'kode' => '01.00A.UGD.2026.0001', 'lokasi_aset_id' => $locations['POLI']]);
        $this->assertSame('01.00A.UGD.2026.0001', app(AssetNumberSequenceIssuer::class)->issueAsset($tenantId,
            'aset:penerimaan:'.DB::table('aset_tr_aset')->where('id', $first)->value('penerimaan_aset_id').':1:1', $entityId, $groupId, $locations['UGD'], '2026-01-15'));
        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, '"number_sequence_counters"') && str_contains($query->sql, 'for update')) {
                $locks[] = $query->bindings[1];
            }
        });
        $requests = [
            ['key' => 'ordered:ugd', 'group_id' => $groupId, 'location_id' => $locations['UGD'], 'acquired_on' => '2026-01-15'],
            ['key' => 'ordered:poli', 'group_id' => $groupId, 'location_id' => $locations['POLI'], 'acquired_on' => '2026-01-15'],
        ];
        $issuer = app(AssetNumberSequenceIssuer::class);
        $issuer->issueAssets($tenantId, $entityId, $requests);
        $firstOrder = array_values(array_unique($locks));
        $locks = [];
        $issuer->issueAssets($tenantId, $entityId, array_map(static fn (array $request): array => [...$request, 'key' => $request['key'].':reversed'], array_reverse($requests)));
        $this->assertCount(2, $firstOrder);
        $this->assertSame($firstOrder, array_values(array_unique($locks)), 'Urutan lock counter harus sama walau baris dokumen dibalik.');
    }
}
