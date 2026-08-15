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
 * Siklus hidup aset setelah diterima: dibaca kembali, dikoreksi, dan akhirnya dilepas.
 *
 * Sebelumnya register aset hanya dapat ditulis satu kali dan tidak pernah benar-benar
 * berakhir: nilai atribut masuk tanpa jalan baca, salah isi hanya bisa diperbaiki dengan
 * membuat aset baru, dan `lifecycle_state` tidak pernah menjadi `disposed` sehingga aset
 * yang sudah dijual masih menerima proposal penyusutan.
 */
class AssetLifecycleTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    private int $issued = 0;

    /** Satuan milik Core; tipe atribut merujuknya, tidak mengetik kodenya sendiri. */
    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        $this->unitId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/units-of-measure')) {
                return Http::response(['data' => [[
                    'id' => $this->unitId, 'code' => 'kVA', 'name' => 'Kilovolt-ampere',
                    'symbol' => 'kVA', 'decimal_places' => 2,
                ]]]);
            }

            return Http::response(['data' => ['number' => 'NS-'.str_pad((string) ++$this->issued, 6, '0', STR_PAD_LEFT)]]);
        });
    }

    public function test_aset_menyimpan_snapshot_versi_fiskal_saat_diterima(): void
    {
        $reference = $this->fiscalReference();
        $group = $this->master('group-aset', [
            'nama' => 'Group fiskal',
            'kelompok_harta_fiskal_id' => $reference,
        ]);
        $asset = $this->receive(['group_aset_id' => $group]);

        $this->assertDatabaseHas('tr_penerimaan_aset', [
            'id' => $asset,
            'kelompok_harta_fiskal_id' => $reference,
        ]);
        $this->show($asset)->assertOk()->assertJsonPath('data.kelompok_harta_fiskal_id', $reference);
    }

    public function test_nilai_atribut_dapat_dibaca_kembali_lewat_detail_aset(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Genset']);
        $daya = $this->master('tipe-atribut', ['nama' => 'Daya', 'data_type' => 'decimal', 'satuan_id' => $this->unitId]);
        $garansi = $this->master('tipe-atribut', ['nama' => 'Bergaransi', 'data_type' => 'boolean']);
        $this->attach($jenis, [['tipe_atribut_id' => $daya], ['tipe_atribut_id' => $garansi]])->assertOk();
        $asset = $this->receive(['jenis_aset_id' => $jenis, 'atribut' => [
            ['tipe_atribut_id' => $daya, 'nilai' => 75.5],
            ['tipe_atribut_id' => $garansi, 'nilai' => true],
        ]]);

        $detail = $this->show($asset)->assertOk();

        // Nilai disimpan di kolom bertipe, tetapi disajikan kembali sebagai satu kunci
        // `nilai` sehingga klien tidak perlu tahu kolom mana yang terpakai. Dicari
        // berdasarkan id, bukan posisi: penyajiannya terurut menurut nama atribut.
        $byId = collect($detail->json('data.atribut'))->keyBy('tipe_atribut_id');
        $this->assertSame(75.5, $byId[$daya]['nilai']);
        $this->assertSame('kVA', $byId[$daya]['satuan']);
        $this->assertTrue($byId[$garansi]['nilai']);
    }

    public function test_aset_dapat_dikoreksi_termasuk_atributnya(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Genset']);
        $daya = $this->master('tipe-atribut', ['nama' => 'Daya', 'data_type' => 'decimal']);
        $this->attach($jenis, [['tipe_atribut_id' => $daya]])->assertOk();
        $pabrikan = $this->master('pabrikan-aset', ['nama' => 'Yanmar']);
        $model = $this->master('model-aset', ['nama' => 'YM-200', 'pabrikan_aset_id' => $pabrikan]);
        $asset = $this->receive(['jenis_aset_id' => $jenis, 'atribut' => [['tipe_atribut_id' => $daya, 'nilai' => 10]]]);

        $this->correct($asset, [
            'pabrikan_aset_id' => $pabrikan,
            'model_aset_id' => $model,
            'serial_number' => 'SN-9',
            'keterangan' => 'Dikoreksi setelah cek fisik',
            'atribut' => [['tipe_atribut_id' => $daya, 'nilai' => 90]],
        ])->assertOk()
            ->assertJsonPath('data.model_aset_id', $model)
            ->assertJsonPath('data.serial_number', 'SN-9')
            // JSON tidak membedakan 90 dari 90.0, jadi dibandingkan sebagai angka.
            ->assertJsonPath('data.atribut.0.nilai', fn ($nilai): bool => (float) $nilai === 90.0);

        // Atribut diganti, bukan ditumpuk: satu aset tetap satu nilai per atribut.
        $this->assertSame(1, DB::table('tr_aset_atribut')->where('asset_id', $asset)->count());
    }

    public function test_kombinasi_jenis_pabrikan_dan_model_harus_sesuai_konfigurasi(): void
    {
        $jenisA = $this->master('jenis-aset', ['nama' => 'Genset A']);
        $jenisB = $this->master('jenis-aset', ['nama' => 'Genset B']);
        $pabrikanA = $this->master('pabrikan-aset', ['nama' => 'Yanmar']);
        $pabrikanB = $this->master('pabrikan-aset', ['nama' => 'Komatsu']);
        $modelA = $this->master('model-aset', [
            'nama' => 'YM-200',
            'pabrikan_aset_id' => $pabrikanA,
            'jenis_aset_id' => $jenisA,
        ]);
        $modelBebas = $this->master('model-aset', [
            'nama' => 'Model umum',
            'pabrikan_aset_id' => $pabrikanA,
        ]);

        $this->receiveResponse([
            'jenis_aset_id' => $jenisA,
            'pabrikan_aset_id' => $pabrikanB,
            'model_aset_id' => $modelA,
        ])->assertStatus(422)->assertJsonValidationErrors('model_aset_id');

        $this->receiveResponse([
            'jenis_aset_id' => $jenisB,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelA,
        ])->assertStatus(422)->assertJsonValidationErrors('model_aset_id');

        $this->receiveResponse([
            'jenis_aset_id' => $jenisA,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelBebas,
        ])->assertStatus(422)->assertJsonValidationErrors('model_aset_id');

        $this->receive([
            'jenis_aset_id' => $jenisB,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelBebas,
        ]);

        $asset = $this->receive([
            'jenis_aset_id' => $jenisA,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelA,
        ]);
        $this->correct($asset, ['jenis_aset_id' => $jenisB])
            ->assertStatus(422)
            ->assertJsonValidationErrors('model_aset_id');
    }

    public function test_group_aset_tidak_dapat_diganti_karena_buku_sudah_terbentuk(): void
    {
        $asset = $this->receive();
        $lain = $this->master('group-aset', ['nama' => 'Group lain']);

        $this->correct($asset, ['group_aset_id' => $lain])
            ->assertStatus(422)
            ->assertJsonValidationErrors('group_aset_id');
    }

    public function test_aset_tidak_dapat_ditempatkan_tanpa_buku_dan_profil_efektif(): void
    {
        $asset = $this->receive();

        $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.mutate']))
            ->postJson('/api/v1/aset/'.$asset.'/penempatan', [
                'effective_on' => '2026-06-15',
                'reason' => 'Mulai dipakai',
                'usage_org_unit_id' => $this->orgUnitId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('group_aset_id');

        $this->assertSame('received', DB::table('tr_penerimaan_aset')->where('id', $asset)->value('lifecycle_state'));
        $this->assertSame(0, DB::table('tr_buku_aset')->where('asset_id', $asset)->count());
    }

    public function test_profil_yang_belum_berlaku_menolak_penempatan(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Group versi masa depan']);
        $jenis = $this->master('jenis-aset', ['nama' => 'Jenis versi masa depan']);
        $profil = $this->master('profil-penyusutan', [
            'nama' => 'Profil mulai 2027', 'method' => 'straight_line', 'frequency' => 'monthly',
            'year_basis' => 'calendar', 'useful_life_periods' => 12, 'effective_from' => '2027-01-01',
        ]);
        $buku = $this->master('buku-penyusutan', ['nama' => 'Buku versi masa depan', 'depreciation_profile_id' => $profil]);
        $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor('group-aset')))
            ->putJson('/api/v1/group-aset/'.$group.'/buku-penyusutan', ['rows' => [['buku_id' => $buku]]])
            ->assertOk();
        $asset = $this->receive(['group_aset_id' => $group, 'jenis_aset_id' => $jenis]);

        $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.mutate']))
            ->postJson('/api/v1/aset/'.$asset.'/penempatan', [
                'effective_on' => '2026-06-15', 'reason' => 'Mulai dipakai', 'usage_org_unit_id' => $this->orgUnitId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_on');
    }

    public function test_aset_tidak_dapat_menjadi_induk_dirinya_sendiri(): void
    {
        $asset = $this->receive();

        $this->correct($asset, ['parent_asset_id' => $asset])->assertStatus(422);
    }

    public function test_tanggal_mulai_digunakan_menggeser_awal_penyusutan_selama_belum_ada_periode(): void
    {
        $book = $this->bookedAsset(convention: 'full_month');
        $asset = (string) DB::table('tr_buku_aset')->where('id', $book)->value('asset_id');
        $this->assertSame('2026-06-01', $this->startDate($book));

        $this->correct($asset, ['placed_in_service_on' => '2026-09-20'])->assertOk();

        // Konvensi bulan penuh menarik tanggalnya ke awal bulan pemakaian.
        $this->assertSame('2026-09-01', $this->startDate($book));
    }

    public function test_nilai_perolehan_tidak_dapat_diubah_setelah_ada_periode_penyusutan(): void
    {
        $book = $this->bookedAsset();
        $asset = (string) DB::table('tr_buku_aset')->where('id', $book)->value('asset_id');
        $this->propose($book, '2026-07-01', '2026-07-31')->assertCreated();

        $this->correct($asset, ['acquisition_value' => 5_000_000])->assertStatus(409);

        // Sebelum ada periode, koreksi nilai ikut menyesuaikan buku asetnya.
        $lain = $this->bookedAsset();
        $asetLain = (string) DB::table('tr_buku_aset')->where('id', $lain)->value('asset_id');
        $this->correct($asetLain, ['acquisition_value' => 2_400_000])->assertOk();
        $this->assertSame(2_400_000.0, (float) DB::table('tr_buku_aset')->where('id', $lain)->value('net_book_value'));
    }

    public function test_penjualan_melepas_aset_dan_menutup_bukunya(): void
    {
        $book = $this->bookedAsset();
        $asset = (string) DB::table('tr_buku_aset')->where('id', $book)->value('asset_id');
        $this->decommission($asset);

        $this->document('penjualan-aset', $asset, '2026-08-31')->assertCreated();

        $this->assertSame('disposed', DB::table('tr_penerimaan_aset')->where('id', $asset)->value('lifecycle_state'));
        $this->assertSame('closed', DB::table('tr_buku_aset')->where('id', $book)->value('status'));
        $this->assertSame('2026-08-31', substr((string) DB::table('tr_buku_aset')->where('id', $book)->value('closed_on'), 0, 10));

        // Aset yang sudah dilepas tidak boleh menerima penyusutan bulan berikutnya.
        $this->propose($book, '2026-09-01', '2026-09-30')->assertStatus(422);
    }

    public function test_aset_yang_sudah_dilepas_tidak_dapat_dikoreksi(): void
    {
        $book = $this->bookedAsset();
        $asset = (string) DB::table('tr_buku_aset')->where('id', $book)->value('asset_id');
        $this->decommission($asset);
        $this->document('pemusnahan-aset', $asset, '2026-08-31')->assertCreated();

        $this->correct($asset, ['serial_number' => 'SN-baru'])->assertStatus(409);
    }

    public function test_proposal_massal_membuat_periode_untuk_seluruh_buku_aktif(): void
    {
        $books = [$this->bookedAsset(), $this->bookedAsset(), $this->bookedAsset()];
        // Satu buku sudah diusulkan lebih dahulu, satu lagi asetnya sudah dilepas.
        $this->propose($books[0], '2026-07-01', '2026-07-31')->assertCreated();
        $dilepas = (string) DB::table('tr_buku_aset')->where('id', $books[2])->value('asset_id');
        $this->decommission($dilepas);
        $this->document('penjualan-aset', $dilepas, '2026-06-30')->assertCreated();

        $response = $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.penyusutan.create']))
            ->postJson('/api/v1/penyusutan/proposal-massal', [
                'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31',
            ])->assertCreated();

        // Hanya buku kedua yang tersisa; yang sudah punya periode dilewati dengan alasan,
        // yang sudah ditutup tidak ikut terpilih sama sekali.
        $this->assertSame(1, $response->json('data.dibuat'));
        $this->assertSame($books[1], $response->json('data.periode.0.asset_book_id'));
        $this->assertSame('sudah_ada', $response->json('data.rincian_dilewati.0.reason'));
        $this->assertSame(2, DB::table('tr_penyusutan_aset')->count());
    }

    public function test_proposal_massal_dapat_disaring_per_group(): void
    {
        $satu = $this->bookedAsset();
        $dua = $this->bookedAsset();
        $group = (string) DB::table('tr_penerimaan_aset')
            ->where('id', DB::table('tr_buku_aset')->where('id', $satu)->value('asset_id'))
            ->value('group_aset_id');

        $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.penyusutan.create']))
            ->postJson('/api/v1/penyusutan/proposal-massal', [
                'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31', 'group_aset_id' => $group,
            ])->assertCreated()->assertJsonPath('data.dibuat', 1);

        $this->assertSame(1, DB::table('tr_penyusutan_aset')->where('asset_book_id', $satu)->count());
        $this->assertSame(0, DB::table('tr_penyusutan_aset')->where('asset_book_id', $dua)->count());
    }

    public function test_proposal_massal_tidak_menyentuh_tenant_lain(): void
    {
        $this->bookedAsset();

        $this->withHeaders($this->contextHeaders((string) Str::ulid(), ['management-aset.penyusutan.create']))
            ->postJson('/api/v1/penyusutan/proposal-massal', [
                'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31',
            ])->assertCreated()->assertJsonPath('data.dibuat', 0);

        $this->assertSame(0, DB::table('tr_penyusutan_aset')->count());
    }

    // ---- penyusun skenario -------------------------------------------------

    /** Aset lengkap dengan satu buku penyusutan; mengembalikan id buku asetnya. */
    private function bookedAsset(string $convention = 'full_month'): string
    {
        $group = $this->master('group-aset', ['nama' => 'Group '.Str::random(6)]);
        $jenis = $this->master('jenis-aset', ['nama' => 'Jenis '.Str::random(6)]);
        $profil = $this->master('profil-penyusutan', [
            'nama' => 'Profil '.Str::random(6), 'method' => 'straight_line',
            'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 12,
        ]);
        $buku = $this->master('buku-penyusutan', ['nama' => 'Buku '.Str::random(6), 'depreciation_profile_id' => $profil]);
        $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor('group-aset')))
            ->putJson('/api/v1/group-aset/'.$group.'/buku-penyusutan', ['rows' => [[
                'buku_id' => $buku, 'useful_life_periods' => 12, 'convention' => $convention,
            ]]])->assertOk();
        $asset = $this->receive(['group_aset_id' => $group, 'jenis_aset_id' => $jenis]);

        return (string) DB::table('tr_buku_aset')->where('asset_id', $asset)->value('id');
    }

    /** @param array<string, mixed> $overrides */
    private function receive(array $overrides = []): string
    {
        return (string) $this->receiveResponse($overrides)->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $overrides */
    private function receiveResponse(array $overrides = []): TestResponse
    {
        // Master disiapkan lebih dahulu: `master()` memasang header permission miliknya
        // sendiri, jadi memanggilnya di dalam rangkaian permintaan aset akan menimpa
        // header `aset.create` dan permintaannya dibalas 403.
        $group = $overrides['group_aset_id'] ?? $this->master('group-aset', ['nama' => 'Group '.Str::random(6)]);
        $jenis = $overrides['jenis_aset_id'] ?? $this->master('jenis-aset', ['nama' => 'Jenis '.Str::random(6)]);

        return $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.create']))
            ->withHeader('Idempotency-Key', 'aset-'.Str::ulid())
            ->postJson('/api/v1/aset', [
                'legal_entity_id' => $this->legalEntityId,
                'group_aset_id' => $group,
                'jenis_aset_id' => $jenis,
                'pabrikan_aset_id' => $overrides['pabrikan_aset_id'] ?? null,
                'model_aset_id' => $overrides['model_aset_id'] ?? null,
                'acquired_on' => '2026-06-01', 'placed_in_service_on' => '2026-06-15',
                'acquisition_value' => 1_200_000, 'currency_code' => 'IDR',
                'usage_org_unit_id' => $this->orgUnitId,
                'atribut' => $overrides['atribut'] ?? [],
            ]);
    }

    private function fiscalReference(): string
    {
        $id = (string) Str::ulid();
        DB::table('m_kelompok_harta_fiskal')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'template_key' => 'test:asset-fiscal-'.Str::ulid(),
            'jurisdiction' => 'ID',
            'label' => 'Kelompok aset uji',
            'effective_from' => '2023-07-17',
            'useful_life_years' => 4,
            'straight_line_rate_percent' => 25,
            'reducing_balance_rate_percent' => 50,
            'allow_reducing_balance' => true,
            'depreciable' => true,
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function show(string $assetId): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.read']))
            ->getJson('/api/v1/aset/'.$assetId);
    }

    /** @param array<string, mixed> $payload */
    private function correct(string $assetId, array $payload): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.aset.update']))
            ->patchJson('/api/v1/aset/'.$assetId, $payload);
    }

    private function propose(string $bookId, string $start, string $end): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.penyusutan.create']))
            ->postJson('/api/v1/penyusutan/proposal', [
                'asset_book_id' => $bookId, 'period_starts_on' => $start, 'period_ends_on' => $end,
            ]);
    }

    /** Melewati workflow Core: penjualan mensyaratkan aset sudah terdekomisioning. */
    private function decommission(string $assetId): void
    {
        DB::table('tr_penerimaan_aset')->where('id', $assetId)->update(['lifecycle_state' => 'decommissioned']);
    }

    private function document(string $type, string $assetId, string $tanggal): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.'.$type.'.create']))
            ->withHeader('Idempotency-Key', $type.'-'.Str::ulid())
            ->postJson('/api/v1/'.$type, [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'asset_id' => $assetId, 'tanggal' => $tanggal,
            ]);
    }

    private function startDate(string $bookId): string
    {
        return substr((string) DB::table('tr_buku_aset')->where('id', $bookId)->value('depreciation_start_on'), 0, 10);
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

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(fn (string $a): string => 'management-aset.'.$resource.'.'.$a, ['read', 'create', 'update', 'archive']);
    }
}
