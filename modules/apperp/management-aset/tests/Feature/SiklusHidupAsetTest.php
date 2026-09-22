<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Siklus hidup aset setelah diterima: dibaca kembali, dikoreksi, dan akhirnya dilepas.
 *
 * Sebelumnya register aset hanya dapat ditulis satu kali dan tidak pernah benar-benar
 * berakhir: nilai atribut masuk tanpa jalan baca, salah isi hanya bisa diperbaiki dengan
 * membuat aset baru, dan `lifecycle_state` tidak pernah menjadi `disposed` sehingga aset
 * yang sudah dijual masih menerima proposal penyusutan.
 */
class SiklusHidupAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    /** Satuan milik Core; tipe atribut merujuknya, tidak mengetik kodenya sendiri. */
    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        $this->unitId = $this->buatSatuanUji($this->tenantId, 'kVA', 'Kilovolt-ampere');

        // Pemalsuan HTTP yang dulu ada di sini sudah tidak dipakai siapa pun: satuan dan nomor
        // datang dari kontrak Core sejak F3-06 dan F3-08. Gantinya penjaga yang berlawanan arah
        // — permintaan HTTP apa pun yang tersisa akan menggagalkan test, bukan dijawab palsu.
        Http::preventStrayRequests();
    }

    public function test_aset_menyimpan_snapshot_versi_fiskal_saat_diterima(): void
    {
        $reference = $this->fiscalReference();
        $group = $this->master('group-aset', [
            'nama' => 'Group fiskal',
            'kelompok_harta_fiskal_id' => $reference,
        ]);
        $aset = $this->receive(['group_aset_id' => $group]);

        $this->assertDatabaseHas('aset_tr_aset', [
            'id' => $aset,
            'kelompok_harta_fiskal_id' => $reference,
        ]);
        $this->show($aset)->assertOk()->assertJsonPath('data.kelompok_harta_fiskal_id', $reference);
    }

    public function test_nilai_atribut_dapat_dibaca_kembali_lewat_detail_aset(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Genset']);
        $daya = $this->master('tipe-atribut', ['nama' => 'Daya', 'data_type' => 'decimal', 'satuan_id' => $this->unitId]);
        $garansi = $this->master('tipe-atribut', ['nama' => 'Bergaransi', 'data_type' => 'boolean']);
        $this->attach($jenis, [['tipe_atribut_id' => $daya], ['tipe_atribut_id' => $garansi]])->assertOk();
        $aset = $this->receive(['jenis_aset_id' => $jenis, 'atribut' => [
            ['tipe_atribut_id' => $daya, 'nilai' => 75.5],
            ['tipe_atribut_id' => $garansi, 'nilai' => true],
        ]]);

        $detail = $this->show($aset)->assertOk();

        // Nilai disimpan di kolom bertipe, tetapi disajikan kembali sebagai satu kunci
        // `nilai` sehingga klien tidak perlu tahu kolom mana yang terpakai. Dicari
        // berdasarkan id, bukan posisi: penyajiannya terurut menurut nama atribut.
        $atribut = $detail->json('data.atribut');

        if (! is_array($atribut)) {
            $this->fail('Detail aset tidak memuat daftar atribut.');
        }

        $byId = collect($atribut)->keyBy('tipe_atribut_id');
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
        $aset = $this->receive(['jenis_aset_id' => $jenis, 'atribut' => [['tipe_atribut_id' => $daya, 'nilai' => 10]]]);

        $this->correct($aset, [
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
        $this->assertSame(1, DB::table('aset_tr_aset_atribut')->where('aset_id', $aset)->count());
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
        ])->assertStatus(422)->assertJsonValidationErrors('details.0.model_aset_id');

        $this->receiveResponse([
            'jenis_aset_id' => $jenisB,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelA,
        ])->assertStatus(422)->assertJsonValidationErrors('details.0.model_aset_id');

        $this->receiveResponse([
            'jenis_aset_id' => $jenisA,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelBebas,
        ])->assertStatus(422)->assertJsonValidationErrors('details.0.model_aset_id');

        $this->receive([
            'jenis_aset_id' => $jenisB,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelBebas,
        ]);

        $aset = $this->receive([
            'jenis_aset_id' => $jenisA,
            'pabrikan_aset_id' => $pabrikanA,
            'model_aset_id' => $modelA,
        ]);
        $this->correct($aset, ['jenis_aset_id' => $jenisB])
            ->assertStatus(422)
            ->assertJsonValidationErrors('model_aset_id');
    }

    public function test_group_aset_tidak_dapat_diganti_karena_buku_sudah_terbentuk(): void
    {
        $aset = $this->receive();
        $lain = $this->master('group-aset', ['nama' => 'Group lain']);

        $this->correct($aset, ['group_aset_id' => $lain])
            ->assertStatus(422)
            ->assertJsonValidationErrors('group_aset_id');
    }

    public function test_aset_tidak_dapat_menjadi_induk_dirinya_sendiri(): void
    {
        $aset = $this->receive();

        $this->correct($aset, ['induk_aset_id' => $aset])->assertStatus(422);
    }

    public function test_tanggal_mulai_digunakan_menggeser_awal_penyusutan_selama_belum_ada_periode(): void
    {
        $book = $this->bookedAset(convention: 'full_month');
        $aset = (string) DB::table('aset_tr_buku_aset')->where('id', $book)->value('aset_id');
        $this->assertSame('2026-06-01', $this->startDate($book));

        $this->correct($aset, ['placed_in_service_on' => '2026-09-20'])->assertOk();

        // Konvensi bulan penuh menarik tanggalnya ke awal bulan pemakaian.
        $this->assertSame('2026-09-01', $this->startDate($book));
    }

    public function test_nilai_perolehan_tidak_dapat_diubah_setelah_ada_periode_penyusutan(): void
    {
        $book = $this->bookedAset();
        $aset = (string) DB::table('aset_tr_buku_aset')->where('id', $book)->value('aset_id');
        $this->propose($book, '2026-07-01', '2026-07-31')->assertCreated();

        $this->correct($aset, ['acquisition_value' => 5_000_000])->assertStatus(409);

        // Sebelum ada periode, koreksi nilai ikut menyesuaikan buku asetnya.
        $lain = $this->bookedAset();
        $asetLain = (string) DB::table('aset_tr_buku_aset')->where('id', $lain)->value('aset_id');
        $this->correct($asetLain, ['acquisition_value' => 2_400_000])->assertOk();
        $this->assertSame(2_400_000.0, (float) DB::table('aset_tr_buku_aset')->where('id', $lain)->value('net_book_value'));
    }

    public function test_penjualan_melepas_aset_dan_menutup_bukunya(): void
    {
        $book = $this->bookedAset();
        $aset = (string) DB::table('aset_tr_buku_aset')->where('id', $book)->value('aset_id');
        $this->decommission($aset);

        $this->document('penjualan-aset', $aset, '2026-08-31')->assertCreated();

        $this->assertSame('disposed', DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));
        $this->assertSame('closed', DB::table('aset_tr_buku_aset')->where('id', $book)->value('status'));
        $this->assertSame('2026-08-31', substr((string) DB::table('aset_tr_buku_aset')->where('id', $book)->value('closed_on'), 0, 10));

        // Aset yang sudah dilepas tidak boleh menerima penyusutan bulan berikutnya.
        $this->propose($book, '2026-09-01', '2026-09-30')->assertStatus(422);
    }

    public function test_aset_yang_sudah_dilepas_tidak_dapat_dikoreksi(): void
    {
        $book = $this->bookedAset();
        $aset = (string) DB::table('aset_tr_buku_aset')->where('id', $book)->value('aset_id');
        $this->decommission($aset);
        $this->document('pemusnahan-aset', $aset, '2026-08-31')->assertCreated();

        $this->correct($aset, ['serial_number' => 'SN-baru'])->assertStatus(409);
    }

    public function test_proposal_massal_membuat_periode_untuk_seluruh_buku_aktif(): void
    {
        $books = [$this->bookedAset(), $this->bookedAset(), $this->bookedAset()];
        // Satu buku sudah diusulkan lebih dahulu, satu lagi asetnya sudah dilepas.
        $this->propose($books[0], '2026-07-01', '2026-07-31')->assertCreated();
        $dilepas = (string) DB::table('aset_tr_buku_aset')->where('id', $books[2])->value('aset_id');
        $this->decommission($dilepas);
        $this->document('penjualan-aset', $dilepas, '2026-06-30')->assertCreated();

        $response = $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/proposal-massal', [
                'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31',
            ])->assertCreated();

        // Hanya buku kedua yang tersisa; yang sudah punya periode dilewati dengan alasan,
        // yang sudah ditutup tidak ikut terpilih sama sekali.
        $this->assertSame(1, $response->json('data.dibuat'));
        $this->assertSame($books[1], $response->json('data.periode.0.buku_aset_id'));
        $this->assertSame('sudah_ada', $response->json('data.rincian_dilewati.0.reason'));
        $this->assertSame(2, DB::table('aset_tr_penyusutan_aset')->count());
    }

    public function test_proposal_massal_dapat_disaring_per_group(): void
    {
        $satu = $this->bookedAset();
        $dua = $this->bookedAset();
        $group = (string) DB::table('aset_tr_aset')
            ->where('id', DB::table('aset_tr_buku_aset')->where('id', $satu)->value('aset_id'))
            ->value('group_aset_id');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/proposal-massal', [
                'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31', 'group_aset_id' => $group,
            ])->assertCreated()->assertJsonPath('data.dibuat', 1);

        $this->assertSame(1, DB::table('aset_tr_penyusutan_aset')->where('buku_aset_id', $satu)->count());
        $this->assertSame(0, DB::table('aset_tr_penyusutan_aset')->where('buku_aset_id', $dua)->count());
    }

    public function test_proposal_massal_tidak_menyentuh_tenant_lain(): void
    {
        $this->bookedAset();

        $this->sebagaiPengguna((string) Str::ulid(), ['management-aset.penyusutan.create'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/proposal-massal', [
                'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31',
            ])->assertCreated()->assertJsonPath('data.dibuat', 0);

        $this->assertSame(0, DB::table('aset_tr_penyusutan_aset')->count());
    }

    // ---- penyusun skenario -------------------------------------------------

    /** Aset lengkap dengan satu buku penyusutan; mengembalikan id buku asetnya. */
    private function bookedAset(string $convention = 'full_month'): string
    {
        $group = $this->master('group-aset', ['nama' => 'Group '.Str::random(6)]);
        $jenis = $this->master('jenis-aset', ['nama' => 'Jenis '.Str::random(6)]);
        $profil = $this->master('profil-penyusutan', [
            'nama' => 'Profil '.Str::random(6), 'method' => 'straight_line',
            'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 12,
        ]);
        $buku = $this->master('buku-penyusutan', ['nama' => 'Buku '.Str::random(6), 'depreciation_profile_id' => $profil]);
        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('group-aset'))
            ->putJson('/api/modules/management-aset/v1/group-aset/'.$group.'/buku-penyusutan', ['rows' => [[
                'buku_id' => $buku, 'useful_life_periods' => 12, 'convention' => $convention,
            ]]])->assertOk();
        $aset = $this->receive(['group_aset_id' => $group, 'jenis_aset_id' => $jenis]);

        return (string) DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->value('id');
    }

    /** @param array<string, mixed> $overrides */
    private function receive(array $overrides = []): string
    {
        $penerimaan = (string) $this->receiveResponse($overrides)->assertCreated()->json('data.id');
        $this->selesaikanPenerimaan($this->tenantId, $penerimaan)->assertOk();

        return (string) DB::table('aset_tr_aset')->where('penerimaan_aset_id', $penerimaan)->value('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function receiveResponse(array $overrides = []): TestResponse
    {
        // Master disiapkan lebih dahulu: `master()` memasang header permission miliknya
        // sendiri, jadi memanggilnya di dalam rangkaian permintaan aset akan menimpa
        // header `aset.create` dan permintaannya dibalas 403.
        $group = $overrides['group_aset_id'] ?? $this->master('group-aset', ['nama' => 'Group '.Str::random(6)]);
        $jenis = $overrides['jenis_aset_id'] ?? $this->master('jenis-aset', ['nama' => 'Jenis '.Str::random(6)]);

        return $this->drafPenerimaan($this->tenantId, [
            'legal_entity_id' => $this->legalEntityId,
            'nama' => $overrides['nama'] ?? 'Aset lifecycle uji',
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
        DB::table('aset_m_kelompok_harta_fiskal')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'template_key' => 'test:aset-fiscal-'.Str::ulid(),
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

    /** @return TestResponse<Response> */
    private function show(string $asetId): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'])
            ->getJson('/api/modules/management-aset/v1/aset/'.$asetId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function correct(string $asetId, array $payload): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.update'])
            ->patchJson('/api/modules/management-aset/v1/aset/'.$asetId, $payload);
    }

    /** @return TestResponse<Response> */
    private function propose(string $bookId, string $start, string $end): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/proposal', [
                'buku_aset_id' => $bookId, 'period_starts_on' => $start, 'period_ends_on' => $end,
            ]);
    }

    /** Melewati workflow Core: penjualan mensyaratkan aset sudah terdekomisioning. */
    private function decommission(string $asetId): void
    {
        DB::table('aset_tr_aset')->where('id', $asetId)->update(['lifecycle_state' => 'decommissioned']);
    }

    /** @return TestResponse<Response> */
    private function document(string $type, string $asetId, string $tanggal): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$type.'.create'])
            ->withHeader('Idempotency-Key', $type.'-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$type, [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'aset_id' => $asetId, 'tanggal' => $tanggal,
            ]);
    }

    private function startDate(string $bookId): string
    {
        return substr((string) DB::table('aset_tr_buku_aset')->where('id', $bookId)->value('depreciation_start_on'), 0, 10);
    }

    /** @param array<string, mixed> $payload */
    private function master(string $resource, array $payload): string
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor($resource))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, $this->denganKodeKetik($resource, $payload))
            ->assertCreated()->json('data.id');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return TestResponse<Response>
     */
    private function attach(string $jenisId, array $rows): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('jenis-aset'))
            ->putJson('/api/modules/management-aset/v1/jenis-aset/'.$jenisId.'/atribut', ['rows' => $rows]);
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(fn (string $a): string => 'management-aset.'.$resource.'.'.$a, ['read', 'create', 'update', 'archive']);
    }
}
