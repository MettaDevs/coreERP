<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

/**
 * Atribut dinamis adalah pengganti penambahan tingkat klasifikasi: client yang ingin
 * membedakan aset lebih rinci menambah atribut, bukan tabel. Karena definisinya dibuat
 * tenant saat berjalan, aturannya harus ditegakkan dari database, bukan dari kode.
 */
class AssetAttributeTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    private int $issued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(fn () => Http::response(['data' => ['number' => 'NS-'.str_pad((string) ++$this->issued, 6, '0', STR_PAD_LEFT)]]));
    }

    public function test_aset_menyimpan_nilai_atribut_sesuai_tipenya(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $kapasitas = $this->master('tipe-atribut', ['nama' => 'Kapasitas', 'data_type' => 'number', 'satuan' => 'liter']);
        $bergaransi = $this->master('tipe-atribut', ['nama' => 'Bergaransi', 'data_type' => 'boolean']);
        $dipasang = $this->master('tipe-atribut', ['nama' => 'Tanggal pasang', 'data_type' => 'date']);
        $this->attach($jenis, [
            ['tipe_atribut_id' => $kapasitas, 'wajib' => true],
            ['tipe_atribut_id' => $bergaransi],
            ['tipe_atribut_id' => $dipasang],
        ])->assertOk()->assertJsonCount(3, 'data');

        $asset = $this->receive($jenis, [
            ['tipe_atribut_id' => $kapasitas, 'nilai' => 100.5],
            ['tipe_atribut_id' => $bergaransi, 'nilai' => true],
            ['tipe_atribut_id' => $dipasang, 'nilai' => '2026-03-20'],
        ]);

        // Tiap tipe mendarat di kolom yang benar, bukan semuanya jadi teks.
        // Dibandingkan sebagai angka: PostgreSQL mengembalikan decimal sebagai string
        // ("100.500000") sedangkan SQLite sebagai float.
        $this->assertSame(100.5, (float) DB::table('tr_aset_atribut')->where(['asset_id' => $asset, 'tipe_atribut_id' => $kapasitas])->value('nilai_number'));
        $this->assertTrue((bool) DB::table('tr_aset_atribut')->where(['asset_id' => $asset, 'tipe_atribut_id' => $bergaransi])->value('nilai_boolean'));
        $this->assertSame('2026-03-20', substr((string) DB::table('tr_aset_atribut')->where(['asset_id' => $asset, 'tipe_atribut_id' => $dipasang])->value('nilai_date'), 0, 10));
    }

    public function test_atribut_wajib_yang_kosong_ditolak(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $kapasitas = $this->master('tipe-atribut', ['nama' => 'Kapasitas', 'data_type' => 'number']);
        $this->attach($jenis, [['tipe_atribut_id' => $kapasitas, 'wajib' => true]])->assertOk();

        $this->receiveRaw($jenis, [])->assertStatus(422)->assertJsonValidationErrors('atribut.'.$kapasitas);
        $this->assertDatabaseCount('tr_penerimaan_aset', 0);
    }

    public function test_atribut_yang_tidak_terdaftar_pada_jenis_ditolak(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $liar = $this->master('tipe-atribut', ['nama' => 'Warna', 'data_type' => 'text']);

        // Tidak di-attach ke jenis mana pun, jadi mengirimnya hampir pasti salah jenis.
        $this->receiveRaw($jenis, [['tipe_atribut_id' => $liar, 'nilai' => 'Merah']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('atribut.0.tipe_atribut_id');
    }

    public function test_daftar_tetap_hanya_menerima_nilai_dari_daftarnya(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kendaraan']);
        $bahanBakar = $this->master('tipe-atribut', ['nama' => 'Bahan bakar', 'data_type' => 'fixed_list']);
        $this->values($bahanBakar, [['nilai' => 'Bensin'], ['nilai' => 'Solar', 'urutan' => 1]])
            ->assertOk()->assertJsonCount(2, 'data');
        $this->attach($jenis, [['tipe_atribut_id' => $bahanBakar]])->assertOk();

        $this->receiveRaw($jenis, [['tipe_atribut_id' => $bahanBakar, 'nilai' => 'Nuklir']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('atribut.'.$bahanBakar);

        $asset = $this->receive($jenis, [['tipe_atribut_id' => $bahanBakar, 'nilai' => 'Solar']]);
        // Nilai terpilih ditautkan ke barisnya, bukan sekadar disalin sebagai teks.
        $row = DB::table('tr_aset_atribut')->where('asset_id', $asset)->first();
        $this->assertNotNull($row->tipe_atribut_nilai_id);
        $this->assertSame('Solar', $row->nilai_text);
    }

    public function test_rentang_nilai_menolak_angka_di_luar_batas(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Genset']);
        $daya = $this->master('tipe-atribut', ['nama' => 'Daya', 'data_type' => 'value_range', 'min_value' => 10, 'max_value' => 100, 'satuan' => 'kVA']);
        $this->attach($jenis, [['tipe_atribut_id' => $daya]])->assertOk();

        $this->receiveRaw($jenis, [['tipe_atribut_id' => $daya, 'nilai' => 250]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('atribut.'.$daya);
        $this->receiveRaw($jenis, [['tipe_atribut_id' => $daya, 'nilai' => 'bukan angka']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('atribut.'.$daya);

        $this->receive($jenis, [['tipe_atribut_id' => $daya, 'nilai' => 50]]);
    }

    public function test_rentang_nilai_wajib_punya_batas_saat_dibuat(): void
    {
        $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor('tipe-atribut')))
            ->withHeader('Idempotency-Key', 'tanpa-batas')
            ->postJson('/api/v1/tipe-atribut', ['nama' => 'Daya', 'data_type' => 'value_range'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['min_value', 'max_value']);
    }

    public function test_definisi_atribut_jenis_dapat_dibaca_untuk_merender_form(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kendaraan']);
        $bahanBakar = $this->master('tipe-atribut', ['nama' => 'Bahan bakar', 'data_type' => 'fixed_list']);
        $this->values($bahanBakar, [['nilai' => 'Solar', 'urutan' => 1], ['nilai' => 'Bensin', 'urutan' => 0]])->assertOk();
        $this->attach($jenis, [['tipe_atribut_id' => $bahanBakar, 'wajib' => true]])->assertOk();

        $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.jenis-aset.read']))
            ->getJson('/api/v1/jenis-aset/'.$jenis.'/atribut-definisi')
            ->assertOk()
            ->assertJsonPath('data.0.data_type', 'fixed_list')
            ->assertJsonPath('data.0.wajib', true)
            // Pilihan tersaji terurut sesuai kolom urutan, bukan urutan penyimpanan.
            ->assertJsonPath('data.0.nilai_pilihan.0.nilai', 'Bensin')
            ->assertJsonPath('data.0.nilai_pilihan.1.nilai', 'Solar');
    }

    public function test_tipe_atribut_yang_masih_dipakai_tidak_dapat_diarsipkan(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $atribut = $this->master('tipe-atribut', ['nama' => 'Kapasitas', 'data_type' => 'number']);
        $this->attach($jenis, [['tipe_atribut_id' => $atribut]])->assertOk();

        $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor('tipe-atribut')))
            ->deleteJson('/api/v1/tipe-atribut/'.$atribut)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');
    }

    /** @param array<string, mixed> $payload */
    private function master(string $resource, array $payload): string
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor($resource)))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/v1/'.$resource, $payload)
            ->assertCreated()->json('data.id');
    }

    /** @param list<array<string, mixed>> $rows */
    private function attach(string $jenisId, array $rows): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor('jenis-aset')))
            ->putJson('/api/v1/jenis-aset/'.$jenisId.'/atribut', ['rows' => $rows]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function values(string $atributId, array $rows): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor('tipe-atribut')))
            ->putJson('/api/v1/tipe-atribut/'.$atributId.'/nilai', ['rows' => $rows]);
    }

    /** @param list<array<string, mixed>> $atribut */
    private function receive(string $jenisId, array $atribut): string
    {
        return $this->receiveRaw($jenisId, $atribut)->assertCreated()->json('data.id');
    }

    /** @param list<array<string, mixed>> $atribut */
    private function receiveRaw(string $jenisId, array $atribut): TestResponse
    {
        $group = $this->master('group-aset', ['nama' => 'Group '.Str::random(5)]);

        return $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.create']))
            ->withHeader('Idempotency-Key', 'aset-'.Str::ulid())
            ->postJson('/api/v1/aset', [
                'legal_entity_id' => (string) Str::ulid(),
                'group_aset_id' => $group, 'jenis_aset_id' => $jenisId,
                'acquired_on' => '2026-03-20', 'acquisition_value' => 1000000,
                'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
                'atribut' => $atribut,
            ]);
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(fn (string $a): string => 'management-aset.'.$resource.'.'.$a, ['read', 'create', 'update', 'archive']);
    }
}
