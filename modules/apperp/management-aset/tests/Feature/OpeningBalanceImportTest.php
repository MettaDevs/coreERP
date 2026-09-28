<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Impor saldo awal aset lama dari CSV (feed posting finance, TODO 10.6): berkas menjadi draf penerimaan
 * saldo awal per tanggal perolehan, tanggal siap pakai, dan lokasi, dengan aturan yang sama persis
 * dengan layar, semuanya atau tidak sama sekali.
 */
class OpeningBalanceImportTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerbitkanJurnalPenerimaan, RefreshDatabase;

    private string $group;

    private string $fiskal;

    private string $jenisKode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJurnalPenerimaan();
        $this->group = $this->groupTanpaBuku('KENDARAAN', 'Kendaraan');
        $komersial = $this->buku('KOM-KENDARAAN', 'current');
        $this->fiskal = $this->buku('FIS-KENDARAAN', 'none');
        foreach ([$komersial, $this->fiskal] as $buku) {
            DB::table('aset_m_group_buku_penyusutan')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $this->group,
                'buku_id' => $buku, 'depreciate' => false, 'useful_life_periods' => 96, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->jenisKode = (string) DB::table('aset_m_jenis_aset')->where('id', $this->jenis())->value('kode');
    }

    public function test_a_preview_saves_nothing_and_applying_makes_one_draft_per_acquisition_date(): void
    {
        $csv = implode("\n", [
            'tanggal,tanggal_siap_pakai,nama,group,jenis,jumlah,nilai_per_unit,akumulasi_per_unit,periode_berjalan,akumulasi_per_unit:fis-kendaraan,periode_berjalan:FIS-KENDARAAN',
            "2022-01-15,2022-01-15,Ambulans,kendaraan,{$this->jenisKode},2,120000000,60000000,24,30000000,24",
            "15/01/2022,15/01/2022,Kursi roda,KENDARAAN,{$this->jenisKode},3,1500000.50,750000,24,,",
            "2023-03-01,,Mobil operasional,KENDARAAN,{$this->jenisKode},1,200000000,50000000,12,,",
        ]);

        $pratinjau = $this->impor($csv)->assertOk()->json('data');
        $this->assertSame('preview', $pratinjau['status']);
        $this->assertSame(3, $pratinjau['rows']);
        $this->assertSame([], $pratinjau['rejected']);
        $this->assertSame([[2, 3], [4]], array_column($pratinjau['receipts'], 'lines'));
        $this->assertSame([5, 1], array_column($pratinjau['receipts'], 'jumlah_aset'));
        // 2 × 120 juta + bulat(3 × 1.500.000,50) = 244.500.001,50.
        $this->assertSame(['244500001.50', '200000000.00'], array_column($pratinjau['receipts'], 'nilai'));
        $this->assertSame(0, DB::table('aset_tr_penerimaan_aset')->count());

        $kunci = 'impor-'.Str::ulid();
        $hasil = $this->impor($csv, ['apply' => '1'], $kunci)->assertCreated()->json('data');
        $this->assertSame('applied', $hasil['status']);
        $this->assertCount(2, $hasil['receipts']);
        $draf = DB::table('aset_tr_penerimaan_aset')->orderBy('kode')->get();
        $this->assertSame(['saldo_awal', 'saldo_awal'], $draf->pluck('cara_perolehan')->all());
        $this->assertSame(['draft', 'draft'], $draf->pluck('status')->all());
        $baris = DB::table('aset_tr_penerimaan_aset_details')->where('penerimaan_aset_id', $draf[0]->id)->orderBy('line_number')->get();
        $this->assertSame(['60000000.00', '750000.00'], $baris->pluck('akumulasi_per_unit')->all());
        $this->assertSame('1500000.500000', (string) $baris[1]->nilai_per_unit);
        $this->assertSame([['buku_id' => $this->fiskal, 'akumulasi_per_unit' => '30000000', 'periode_berjalan' => 24]], json_decode((string) $baris[0]->saldo_awal_buku, true));

        // Percobaan ulang dengan kunci yang sama memulangkan draf yang sama.
        $this->impor($csv, ['apply' => '1'], $kunci)->assertOk()->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.receipts.0.id', $hasil['receipts'][0]['id']);
        $this->assertSame(2, DB::table('aset_tr_penerimaan_aset')->count());

        // Drafnya penerimaan saldo awal biasa: diselesaikan per dokumen.
        $this->selesaikan((string) $draf[0]->id)->assertOk();
        $this->assertSame('asset.opening_balance', $this->posting((string) $draf[0]->id, 'AST-OPB-')->payload['posting_type']);
    }

    public function test_a_semicolon_file_reads_numbers_the_way_indonesian_excel_writes_them(): void
    {
        $csv = implode("\r\n", [
            'tanggal;nama;group;jenis;jumlah;nilai_per_unit;akumulasi_per_unit;periode_berjalan',
            "15/01/2022;Ambulans;KENDARAAN;{$this->jenisKode};1;1.500.000,50;750.000;24",
        ]);

        $this->impor($csv, ['apply' => '1'])->assertCreated();

        $baris = DB::table('aset_tr_penerimaan_aset_details')->first();
        $this->assertSame(['1500000.500000', '750000.00', 24], [(string) $baris->nilai_per_unit, (string) $baris->akumulasi_per_unit, (int) $baris->periode_berjalan]);
        $this->assertSame('2022-01-15', substr((string) DB::table('aset_tr_penerimaan_aset')->value('tanggal'), 0, 10));
    }

    public function test_rows_at_different_locations_become_separate_drafts(): void
    {
        $gudang = $this->lokasi('Gudang');
        $klinik = $this->lokasi('Klinik');
        $csv = implode("\n", [
            'tanggal,lokasi,nama,group,jenis,jumlah,nilai_per_unit',
            "2022-01-15,{$gudang['kode']},Rak,KENDARAAN,{$this->jenisKode},1,1000000",
            "2022-01-15,{$klinik['kode']},Ranjang,KENDARAAN,{$this->jenisKode},1,2000000",
            "2022-01-15,{$gudang['kode']},Lemari,KENDARAAN,{$this->jenisKode},1,3000000",
        ]);

        // Lokasi milik kepala dokumen, jadi baris bertanggal sama di lokasi lain menjadi draf lain.
        $this->assertSame([[2, 4], [3]], array_column($this->impor($csv)->assertOk()->json('data.receipts'), 'lines'));
        $this->impor($csv, ['apply' => '1'])->assertCreated();
        $this->assertSame([$gudang['id'], $klinik['id']], DB::table('aset_tr_penerimaan_aset')->orderBy('kode')->pluck('lokasi_aset_id')->all());
    }

    public function test_rejected_rows_are_reported_by_line_and_nothing_is_created(): void
    {
        $csv = implode("\n", [
            'tanggal,nama,group,jenis,jumlah,nilai_per_unit,akumulasi_per_unit,periode_berjalan',
            "2022-01-15,Ambulans,TIDAK-ADA,{$this->jenisKode},1,100000000,0,0",
            "2026-02-01,Mobil baru,KENDARAAN,{$this->jenisKode},1,100000000,0,0",
            "2022-02-01,Kursi,KENDARAAN,{$this->jenisKode},1,1000000,2000000,10",
            "01-02-2022,Meja,KENDARAAN,{$this->jenisKode},dua,1000000,0,0",
            "2022-03-01,Lemari,KENDARAAN,{$this->jenisKode},1,1000000,0,0",
        ]);

        $hasil = $this->impor($csv, ['apply' => '1'])->assertOk()->json('data');
        $this->assertSame('rejected', $hasil['status']);
        $this->assertSame([
            [2, 'group', 'Kode TIDAK-ADA tidak ditemukan di master group.'],
            [3, 'tanggal', 'Tanggal perolehan saldo awal harus sama dengan atau sebelum cutover (01/01/2026). Aset yang diperoleh sesudah cutover dicatat sebagai pembelian atau hibah.'],
            [4, 'akumulasi_per_unit', 'Akumulasi ditambah nilai residu tidak boleh melebihi nilai per unit.'],
            [5, 'tanggal', 'Tanggal ditulis 2022-01-15 atau 15/01/2022.'],
            [5, 'jumlah', '"dua" bukan angka.'],
        ], array_map(static fn (array $baris): array => [$baris['line'], $baris['field'], $baris['reason']], $hasil['rejected']));
        // Baris yang sah tetap diperlihatkan, tetapi tidak dibuat: satu baris salah menahan seluruh berkas.
        $this->assertSame([[6]], array_column($hasil['receipts'], 'lines'));
        $this->assertSame(0, DB::table('aset_tr_penerimaan_aset')->count());
    }

    public function test_unknown_or_missing_columns_reject_the_whole_file(): void
    {
        $this->impor("tanggal,nama,group,jenis,jumlah,nilai,catatan\n2022-01-15,A,KENDARAAN,{$this->jenisKode},1,1,x")->assertOk()
            ->assertJsonPath('data.rejected.0.reason', 'Kolom tidak dikenal: nilai, catatan. Unduh templatnya untuk melihat nama kolom yang benar.');
        $this->impor("tanggal,nama,group,jenis\n2022-01-15,A,KENDARAAN,{$this->jenisKode}")->assertOk()
            ->assertJsonPath('data.rejected.0.reason', 'Kolom wajib belum ada: jumlah, nilai_per_unit.');
    }

    public function test_the_template_lists_the_columns_and_the_import_needs_permission_to_create_receipts(): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.create'])
            ->get(self::API.'penerimaan-aset/impor-saldo-awal/templat')
            ->assertOk()
            ->assertSeeText('tanggal,tanggal_siap_pakai,lokasi,nama,group,jenis,kondisi,jumlah,nilai_per_unit,residu_per_unit,akumulasi_per_unit,periode_berjalan,keterangan', false);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read'])
            ->withHeaders(['Accept' => 'application/json'])
            ->post(self::API.'penerimaan-aset/impor-saldo-awal', $this->formulir('tanggal,nama'))
            ->assertForbidden();
    }

    // ---- penyusun skenario -------------------------------------------------

    private function buku(string $kode, string $postingLayer): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'buku-'.Str::ulid(),
            'kode' => $kode, 'nama' => 'Buku '.$kode, 'aktif' => true, 'posting_layer' => $postingLayer,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{id: string, kode: string} */
    private function lokasi(string $nama): array
    {
        $id = (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.lokasi-aset.create'])
            ->withHeader('Idempotency-Key', 'lokasi-'.Str::ulid())
            ->postJson(self::API.'lokasi-aset', ['nama' => $nama])
            ->assertCreated()
            ->json('data.id');

        return ['id' => $id, 'kode' => (string) DB::table('aset_m_lokasi_aset')->where('id', $id)->value('kode')];
    }

    /**
     * @param  array<string, string>  $tambahan
     * @return TestResponse<Response>
     */
    private function impor(string $csv, array $tambahan = [], ?string $kunci = null): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.create', 'management-aset.penerimaan-aset.read'])
            ->withHeaders(['Accept' => 'application/json', 'Idempotency-Key' => $kunci ?? 'impor-'.Str::ulid()])
            ->post(self::API.'penerimaan-aset/impor-saldo-awal', [...$this->formulir($csv), ...$tambahan]);
    }

    /** @return array<string, mixed> */
    private function formulir(string $csv): array
    {
        return [
            'file' => UploadedFile::fake()->createWithContent('saldo-awal.csv', $csv),
            'legal_entity_id' => $this->le,
            'responsible_org_unit_id' => $this->poli,
        ];
    }
}
