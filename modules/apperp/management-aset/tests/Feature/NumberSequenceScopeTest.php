<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Support\ModuleManifestFiles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class NumberSequenceScopeTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    // Satu tabel berarti satu namespace dokumen, kecuali tiga reference siklus hidup yang
    // dibedakan jenis_dokumen. Daftar ini harus ikut berubah ketika manifest menambah reference.
    private const DOCUMENTS = [
        'management-aset.aset' => 'aset_tr_aset',
        'management-aset.penerimaan-aset' => 'aset_tr_penerimaan_aset',
        'management-aset.perencanaan-aset' => 'aset_tr_perencanaan_aset',
        'management-aset.permintaan-pembelian-aset' => 'aset_tr_permintaan_pengadaan_aset',
        'management-aset.mutasi-aset' => 'aset_tr_mutasi_aset',
        'management-aset.pemeliharaan-aset' => 'aset_tr_pemeliharaan_aset',
        'management-aset.penjualan-aset' => 'aset_tr_dokumen_siklus_aset',
        'management-aset.pemusnahan-aset' => 'aset_tr_dokumen_siklus_aset',
        'management-aset.dekomisioning-aset' => 'aset_tr_dokumen_siklus_aset',
        'management-aset.monitoring-aset' => 'aset_tr_monitoring_aset',
        'management-aset.polis-asuransi' => 'aset_m_polis_asuransi',
        'management-aset.kontrak-servis' => 'aset_tr_kontrak_servis',
        'management-aset.penyesuaian-nilai-aset' => 'aset_tr_penyesuaian_nilai_aset',
        'management-aset.permintaan-pemeliharaan' => 'aset_tr_permintaan_pemeliharaan',
        'management-aset.reklasifikasi-aset' => 'aset_tr_reklasifikasi_aset',
    ];

    public function test_every_legal_entity_reference_has_a_matching_document_index(): void
    {
        $this->buatTenantUji();
        $manifest = ModuleManifestFiles::read(dirname(__DIR__, 2));
        $references = collect($manifest['number_sequences']['references'])
            ->filter(fn (array $reference): bool => in_array('legal_entity', $reference['allowed_scopes'], true))
            ->pluck('code')->sort()->values()->all();
        $expected = array_keys(self::DOCUMENTS);
        sort($expected);
        $this->assertSame($expected, $references, 'Reference baru wajib mempunyai pemeriksaan scope indeks dokumennya.');

        foreach (array_unique(self::DOCUMENTS) as $table) {
            $this->assertScopedIndex($table);
        }
    }

    public function test_all_document_references_issue_independent_numbers_for_two_legal_entities(): void
    {
        $tenantId = $this->buatTenantUji();
        $firstEntity = (string) Str::ulid();
        $secondEntity = (string) Str::ulid();
        $this->pastikanOrganisasiAda($tenantId, $firstEntity, 'legal_entity');
        $this->pastikanOrganisasiAda($tenantId, $secondEntity, 'legal_entity');
        $issuer = app(AssetNumberSequenceIssuer::class);

        foreach (self::DOCUMENTS as $reference => $table) {
            $scope = DB::table('tenant_number_sequences as sequence')
                ->join('app_number_sequence_references as reference', 'reference.id', '=', 'sequence.reference_id')
                ->where('sequence.tenant_id', $tenantId)->where('reference.code', $reference)->value('sequence.scope_type');
            $this->assertSame('legal_entity', $scope, $reference.' memakai fixture scope yang berbeda dari runtime.');
            $first = $issuer->issue($reference, $tenantId, $reference.':first', $firstEntity);
            $second = $issuer->issue($reference, $tenantId, $reference.':second', $secondEntity);
            $this->assertSame($first, $second, $reference.' harus mempunyai counter terpisah per PT.');
            $this->assertSame($first, $issuer->issue($reference, $tenantId, $reference.':first', $firstEntity));
            $this->assertNotSame($first, $issuer->issue($reference, $tenantId, $reference.':next', $firstEntity));
        }
    }

    public function test_index_guard_detects_a_tenant_wide_unique_index(): void
    {
        $this->buatTenantUji();
        DB::statement('DROP INDEX aset_tr_perencanaan_kode_unique');
        DB::statement('CREATE UNIQUE INDEX aset_tr_perencanaan_kode_unique ON aset_tr_perencanaan_aset (tenant_id, kode)');
        $this->expectException(AssertionFailedError::class);
        $this->assertScopedIndex('aset_tr_perencanaan_aset');
    }

    public function test_lifecycle_numbers_are_unique_per_company_and_document_kind(): void
    {
        $tenantId = $this->buatTenantUji();
        $firstEntity = (string) Str::ulid();
        $secondEntity = (string) Str::ulid();
        $this->pastikanOrganisasiAda($tenantId, $firstEntity, 'legal_entity');
        $this->pastikanOrganisasiAda($tenantId, $secondEntity, 'legal_entity');
        $record = [
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId,
            'creation_key' => 'lifecycle:'.Str::ulid(), 'jenis_dokumen' => 'penjualan-aset',
            'kode' => 'DOC-00001', 'legal_entity_id' => $firstEntity, 'tanggal' => '2026-10-06',
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('aset_tr_dokumen_siklus_aset')->insert($record);
        $duplicate = [...$record, 'id' => (string) Str::ulid(), 'creation_key' => 'lifecycle:'.Str::ulid()];
        try {
            DB::transaction(fn () => DB::table('aset_tr_dokumen_siklus_aset')->insert($duplicate));
            $this->fail('Nomor aktif yang sama dalam PT dan jenis dokumen yang sama harus ditolak.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('aset_tr_dokumen_siklus_aset', 1);
        }
        DB::table('aset_tr_dokumen_siklus_aset')->insert([...$duplicate, 'legal_entity_id' => $secondEntity]);
        DB::table('aset_tr_dokumen_siklus_aset')->insert([
            ...$duplicate, 'id' => (string) Str::ulid(), 'creation_key' => 'lifecycle:'.Str::ulid(),
            'jenis_dokumen' => 'pemusnahan-aset',
        ]);
        DB::table('aset_tr_dokumen_siklus_aset')->where('id', $record['id'])->update(['deleted_at' => now()]);
        DB::table('aset_tr_dokumen_siklus_aset')->insert([
            ...$duplicate, 'id' => (string) Str::ulid(), 'creation_key' => 'lifecycle:'.Str::ulid(),
        ]);
        $this->assertDatabaseCount('aset_tr_dokumen_siklus_aset', 4);
    }

    private function assertScopedIndex(string $table): void
    {
        $columns = $table === 'aset_tr_dokumen_siklus_aset'
            ? '(tenant_id, legal_entity_id, jenis_dokumen, kode)'
            : '(tenant_id, legal_entity_id, kode)';
        $indexes = DB::select('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?', [$table]);
        $matching = array_filter($indexes, static fn (object $index): bool => str_contains($index->indexdef, 'UNIQUE')
            && str_contains($index->indexdef, $columns)
            && preg_match('/WHERE \(?deleted_at IS NULL\)?$/', $index->indexdef) === 1);
        $this->assertNotEmpty($matching, $table.' harus menjaga nomor aktif per entitas legal dan namespace dokumen.');
    }
}
