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
 * Atribut dinamis adalah pengganti penambahan tingkat klasifikasi: client yang ingin
 * membedakan aset lebih rinci menambah atribut, bukan tabel. Karena definisinya dibuat
 * tenant saat berjalan, aturannya harus ditegakkan dari database, bukan dari kode.
 */
class AtributAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private string $tenantId;

    private int $issued = 0;

    /** Satuan milik Core yang dikenali stub di bawah; yang lain dianggap tidak ada. */
    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->unitId = $this->buatSatuanUji($this->tenantId);
        $unit = fn (string $id): array => [
            'id' => $id, 'code' => 'cm', 'name' => 'Sentimeter', 'symbol' => 'cm', 'decimal_places' => 2,
        ];
        Http::fake(function ($request) use ($unit) {
            if (str_ends_with($request->url(), '/units-of-measure/resolve')) {
                // Core hanya mengembalikan satuan yang benar-benar ada. Klien yang meminta
                // satuan tak dikenal menerima daftar lebih pendek, dan `resolve()` menolaknya.
                $asked = (array) ($request->data()['unit_ids'] ?? []);

                return Http::response(['data' => array_map($unit, array_values(
                    array_filter($asked, fn ($id) => $id === $this->unitId),
                ))]);
            }
            if (str_ends_with($request->url(), '/units-of-measure')) {
                return Http::response(['data' => [$unit($this->unitId)]]);
            }

            return Http::response(['data' => ['number' => 'NS-'.str_pad((string) ++$this->issued, 6, '0', STR_PAD_LEFT)]]);
        });
    }

    public function test_satuan_tipe_atribut_dirujuk_ke_core_dan_kodenya_disalin(): void
    {
        $id = $this->master('tipe-atribut', [
            'nama' => 'Kedalaman', 'data_type' => 'decimal', 'satuan_id' => $this->unitId,
        ]);

        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
            ->getJson('/api/modules/management-aset/v1/tipe-atribut/'.$id)
            ->assertOk()
            ->assertJsonPath('data.satuan_id', $this->unitId)
            // Kode satuan datang dari Core, bukan dari kiriman klien.
            ->assertJsonPath('data.satuan', 'cm');
    }

    public function test_satuan_yang_tidak_dikenal_core_ditolak(): void
    {
        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
            ->withHeader('Idempotency-Key', 'satuan-asing')
            ->postJson('/api/modules/management-aset/v1/tipe-atribut', [
                'nama' => 'Kedalaman', 'data_type' => 'decimal', 'satuan_id' => (string) Str::ulid(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('satuan_id');
        $this->assertDatabaseCount('aset_m_tipe_atribut', 0);
    }

    /** Satuan tidak bermakna untuk teks atau daftar tetap, jadi tidak boleh diam-diam tersimpan. */
    public function test_satuan_ditolak_untuk_tipe_data_yang_tidak_mengenal_satuan(): void
    {
        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
            ->withHeader('Idempotency-Key', 'satuan-salah-tipe')
            ->postJson('/api/modules/management-aset/v1/tipe-atribut', [
                'nama' => 'Warna', 'data_type' => 'string', 'satuan_id' => $this->unitId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('satuan_id');
    }

    /** Daftar satuan dibaca dari dalam layar tipe atribut, jadi izinnya ikut layar itu. */
    public function test_daftar_satuan_dapat_dibaca_dengan_izin_tipe_atribut(): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.tipe-atribut.read'])
            ->getJson('/api/modules/management-aset/v1/reference-data/units-of-measure')
            ->assertOk()
            ->assertJsonPath('data.0.kode', 'cm');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.read'])
            ->getJson('/api/modules/management-aset/v1/reference-data/units-of-measure')
            ->assertForbidden();
    }

    public function test_aset_menyimpan_nilai_atribut_sesuai_tipenya(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $kapasitas = $this->master('tipe-atribut', ['nama' => 'Kapasitas', 'data_type' => 'decimal']);
        $bergaransi = $this->master('tipe-atribut', ['nama' => 'Bergaransi', 'data_type' => 'boolean']);
        $dipasang = $this->master('tipe-atribut', ['nama' => 'Tanggal pasang', 'data_type' => 'date']);
        $this->attach($jenis, [
            ['tipe_atribut_id' => $kapasitas, 'wajib' => true],
            ['tipe_atribut_id' => $bergaransi],
            ['tipe_atribut_id' => $dipasang],
        ])->assertOk()->assertJsonCount(3, 'data');

        $aset = $this->receive($jenis, [
            ['tipe_atribut_id' => $kapasitas, 'nilai' => 100.5],
            ['tipe_atribut_id' => $bergaransi, 'nilai' => true],
            ['tipe_atribut_id' => $dipasang, 'nilai' => '2026-03-20'],
        ]);

        // Tiap tipe mendarat di kolom yang benar, bukan semuanya jadi teks.
        // Dibandingkan sebagai angka: PostgreSQL mengembalikan decimal sebagai string
        // ("100.500000") sedangkan SQLite sebagai float.
        $this->assertSame(100.5, (float) DB::table('aset_tr_aset_atribut')->where(['aset_id' => $aset, 'tipe_atribut_id' => $kapasitas])->value('nilai_number'));
        $this->assertTrue((bool) DB::table('aset_tr_aset_atribut')->where(['aset_id' => $aset, 'tipe_atribut_id' => $bergaransi])->value('nilai_boolean'));
        $this->assertSame('2026-03-20', substr((string) DB::table('aset_tr_aset_atribut')->where(['aset_id' => $aset, 'tipe_atribut_id' => $dipasang])->value('nilai_date'), 0, 10));
    }

    public function test_detail_jenis_aset_menghitung_atribut_model_dan_aset(): void
    {
        ['jenis' => $jenis] = $this->jenisDenganTurunan();

        $this->detail($jenis, [
            ...$this->permissionsFor('jenis-aset'),
            'management-aset.model-aset.read',
            'management-aset.aset.read',
        ])
            ->assertOk()
            ->assertJsonPath('data.atribut_count', 2)
            ->assertJsonPath('data.model_count', 1)
            ->assertJsonPath('data.aset_count', 1)
            ->assertJsonPath('data.models.0.manufacturer', 'Komatsu')
            ->assertJsonPath('data.models.0.model', 'PC200-8')
            ->assertJsonPath('data.models.0.description', null)
            ->assertJsonCount(0, 'data.available_models');
    }

    /**
     * Angka milik resource lain tidak boleh bocor lewat izin jenis aset. `null`, bukan 0:
     * 0 berarti "tidak ada", dan itu jawaban yang tidak berhak ia terima.
     */
    public function test_detail_menyembunyikan_angka_resource_yang_tidak_boleh_dibaca(): void
    {
        ['jenis' => $jenis] = $this->jenisDenganTurunan();

        $this->detail($jenis, ['management-aset.jenis-aset.read'])
            ->assertOk()
            ->assertJsonPath('data.atribut_count', 2)
            ->assertJsonPath('data.model_count', null)
            ->assertJsonPath('data.aset_count', null)
            ->assertJsonPath('data.models', null)
            ->assertJsonPath('data.available_models', null);
    }

    public function test_model_dapat_dikaitkan_dari_detail_jenis_aset(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $pabrikan = $this->master('pabrikan-aset', ['nama' => 'Komatsu']);
        $modelA = $this->master('model-aset', ['nama' => 'PC200-8', 'pabrikan_aset_id' => $pabrikan]);
        $modelB = $this->master('model-aset', ['nama' => 'PC210-10', 'pabrikan_aset_id' => $pabrikan]);
        $permissions = [...$this->permissionsFor('jenis-aset'), 'management-aset.model-aset.read'];

        $this->sebagaiPengguna($this->tenantId, $permissions)
            ->putJson('/api/modules/management-aset/v1/jenis-aset/'.$jenis.'/models', ['model_ids' => [$modelA, $modelB]])
            ->assertOk()
            ->assertJsonPath('data.model_ids.0', $modelA)
            ->assertJsonPath('data.model_ids.1', $modelB);

        $this->assertDatabaseHas('aset_m_model_aset', ['id' => $modelA, 'jenis_aset_id' => $jenis]);
        $this->assertDatabaseHas('aset_m_model_aset', ['id' => $modelB, 'jenis_aset_id' => $jenis]);
    }

    /**
     * Aset dibatasi tanggung jawab organisasi. Pemegang izin baca aset yang scope-nya tidak
     * mencakup aset tersebut tetap tidak boleh menghitungnya lewat jalur detail.
     */
    public function test_detail_menghormati_batas_tanggung_jawab_organisasi(): void
    {
        ['jenis' => $jenis] = $this->jenisDenganTurunan();
        $permissions = [...$this->permissionsFor('jenis-aset'), 'management-aset.aset.read'];

        // Lingkup kebijakan yang menunjuk organisasi lain: pengguna punya izinnya, tetapi
        // tidak atas organisasi yang memiliki datanya.
        $this->sebagaiPengguna($this->tenantId, $permissions, [[
            'policy_code' => 'management-aset.asset-responsibility',
            'legal_entity_id' => (string) Str::ulid(),
            'organization_id' => (string) Str::ulid(),
        ]])
            ->getJson('/api/modules/management-aset/v1/jenis-aset/'.$jenis.'/detail')
            ->assertOk()
            ->assertJsonPath('data.aset_count', 0)
            // Atribut milik jenis aset, bukan aset, jadi ia tidak ikut dibatasi scope.
            ->assertJsonPath('data.atribut_count', 2);
    }

    public function test_detail_menolak_jenis_aset_milik_tenant_lain(): void
    {
        ['jenis' => $jenis] = $this->jenisDenganTurunan();

        $this->sebagaiPengguna((string) Str::ulid(), $this->permissionsFor('jenis-aset'))
            ->getJson('/api/modules/management-aset/v1/jenis-aset/'.$jenis.'/detail')
            ->assertNotFound();
    }

    /** Daftar master tidak ikut menghitung turunan; itu tugas endpoint detail. */
    public function test_daftar_jenis_aset_tidak_membawa_angka_turunan(): void
    {
        $this->jenisDenganTurunan();

        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('jenis-aset'))
            ->getJson('/api/modules/management-aset/v1/jenis-aset')
            ->assertOk()
            ->assertJsonMissingPath('data.0.atribut_count')
            ->assertJsonMissingPath('data.0.model_count')
            ->assertJsonMissingPath('data.0.aset_count');
    }

    public function test_atribut_wajib_yang_kosong_ditolak(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $kapasitas = $this->master('tipe-atribut', ['nama' => 'Kapasitas', 'data_type' => 'decimal']);
        $this->attach($jenis, [['tipe_atribut_id' => $kapasitas, 'wajib' => true]])->assertOk();

        $this->receiveRaw($jenis, [])->assertStatus(422)->assertJsonValidationErrors('details.0.atribut.'.$kapasitas);
        $this->assertDatabaseCount('aset_tr_aset', 0);
    }

    public function test_atribut_yang_tidak_terdaftar_pada_jenis_ditolak(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $liar = $this->master('tipe-atribut', ['nama' => 'Warna', 'data_type' => 'string']);

        // Tidak di-attach ke jenis mana pun, jadi mengirimnya hampir pasti salah jenis.
        $this->receiveRaw($jenis, [['tipe_atribut_id' => $liar, 'nilai' => 'Merah']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.atribut.0.tipe_atribut_id');
    }

    public function test_daftar_tetap_hanya_menerima_nilai_dari_daftarnya(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kendaraan']);
        $bahanBakar = $this->master('tipe-atribut', ['nama' => 'Bahan bakar', 'data_type' => 'string']);
        $this->values($bahanBakar, [['nilai' => 'Bensin'], ['nilai' => 'Solar', 'urutan' => 1]])
            ->assertOk()->assertJsonCount(2, 'data');
        $this->attach($jenis, [['tipe_atribut_id' => $bahanBakar]])->assertOk();

        $this->receiveRaw($jenis, [['tipe_atribut_id' => $bahanBakar, 'nilai' => 'Nuklir']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.atribut.'.$bahanBakar);

        $aset = $this->receive($jenis, [['tipe_atribut_id' => $bahanBakar, 'nilai' => 'Solar']]);
        // Nilai terpilih ditautkan ke barisnya, bukan sekadar disalin sebagai teks.
        $row = DB::table('aset_tr_aset_atribut')->where('aset_id', $aset)->first();
        $this->assertNotNull($row->tipe_atribut_nilai_id);
        $this->assertSame('Solar', $row->nilai_text);
    }

    public function test_rentang_nilai_menolak_angka_di_luar_batas(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Genset']);
        $daya = $this->master('tipe-atribut', ['nama' => 'Daya', 'data_type' => 'decimal', 'min_value' => 10, 'max_value' => 100]);
        $this->attach($jenis, [['tipe_atribut_id' => $daya]])->assertOk();

        $this->receiveRaw($jenis, [['tipe_atribut_id' => $daya, 'nilai' => 250]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.atribut.'.$daya);
        $this->receiveRaw($jenis, [['tipe_atribut_id' => $daya, 'nilai' => 'bukan angka']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.atribut.'.$daya);

        $this->receive($jenis, [['tipe_atribut_id' => $daya, 'nilai' => 50]]);
    }

    public function test_batas_nilai_harus_diisi_berpasangan(): void
    {
        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
            ->withHeader('Idempotency-Key', 'tanpa-batas')
            ->postJson('/api/modules/management-aset/v1/tipe-atribut', ['nama' => 'Daya', 'data_type' => 'decimal', 'min_value' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['min_value', 'max_value']);
    }

    public function test_definisi_atribut_jenis_dapat_dibaca_untuk_merender_form(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kendaraan']);
        $bahanBakar = $this->master('tipe-atribut', ['nama' => 'Bahan bakar', 'data_type' => 'string']);
        $this->values($bahanBakar, [['nilai' => 'Solar', 'urutan' => 1], ['nilai' => 'Bensin', 'urutan' => 0]])->assertOk();
        $this->attach($jenis, [['tipe_atribut_id' => $bahanBakar, 'wajib' => true]])->assertOk();

        $this->sebagaiPengguna($this->tenantId, ['management-aset.jenis-aset.read'])
            ->getJson('/api/modules/management-aset/v1/jenis-aset/'.$jenis.'/atribut-definisi')
            ->assertOk()
            ->assertJsonPath('data.0.data_type', 'string')
            ->assertJsonPath('data.0.wajib', true)
            // Pilihan tersaji terurut sesuai kolom urutan, bukan urutan penyimpanan.
            ->assertJsonPath('data.0.nilai_pilihan.0.nilai', 'Bensin')
            ->assertJsonPath('data.0.nilai_pilihan.1.nilai', 'Solar');
    }

    public function test_enum_lama_ditolak_dan_integer_menolak_pecahan(): void
    {
        foreach (['text', 'number', 'fixed_list', 'value_range'] as $legacy) {
            $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
                ->withHeader('Idempotency-Key', 'legacy-'.$legacy)
                ->postJson('/api/modules/management-aset/v1/tipe-atribut', ['nama' => 'Legacy '.$legacy, 'data_type' => $legacy])
                ->assertStatus(422)
                ->assertJsonValidationErrors('data_type');
        }

        $jenis = $this->master('jenis-aset', ['nama' => 'Mesin ukur']);
        $desimal = $this->master('tipe-atribut', ['nama' => 'Presisi', 'data_type' => 'decimal']);
        $bulat = $this->master('tipe-atribut', ['nama' => 'Jumlah kanal', 'data_type' => 'integer']);
        $this->attach($jenis, [
            ['tipe_atribut_id' => $desimal],
            ['tipe_atribut_id' => $bulat],
        ])->assertOk();

        $this->receiveRaw($jenis, [
            ['tipe_atribut_id' => $desimal, 'nilai' => 1.5],
            ['tipe_atribut_id' => $bulat, 'nilai' => 2.5],
        ])->assertStatus(422)->assertJsonValidationErrors('details.0.atribut.'.$bulat);

        $this->receive($jenis, [
            ['tipe_atribut_id' => $desimal, 'nilai' => 1.5],
            ['tipe_atribut_id' => $bulat, 'nilai' => 2],
        ]);
    }

    public function test_tipe_data_dapat_diubah_sebelum_dipakai_lalu_terkunci_permanen(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Pompa']);
        $atribut = $this->master('tipe-atribut', ['nama' => 'Tekanan', 'data_type' => 'string']);
        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'));

        $this
            ->patchJson('/api/modules/management-aset/v1/tipe-atribut/'.$atribut, ['data_type' => 'decimal'])
            ->assertOk()
            ->assertJsonPath('data.data_type', 'decimal')
            ->assertJsonPath('data.data_type_locked', false);

        $this->attach($jenis, [['tipe_atribut_id' => $atribut]])->assertOk();
        $this->receive($jenis, [['tipe_atribut_id' => $atribut, 'nilai' => 12.5]]);

        // Masuk lagi dengan izin tipe atribut: dua pemanggilan di atas berganti pengguna, dan
        // identitas sekarang bertahan antar permintaan — dulu tiap permintaan membawa
        // headernya sendiri, jadi urutannya tidak berpengaruh.
        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'));

        $this
            ->patchJson('/api/modules/management-aset/v1/tipe-atribut/'.$atribut, ['data_type' => 'integer'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'data_type_locked');
        $this->assertDatabaseHas('aset_m_tipe_atribut', [
            'tenant_id' => $this->tenantId,
            'id' => $atribut,
            'data_type' => 'decimal',
            'data_type_locked' => true,
        ]);
    }

    public function test_values_melindungi_nilai_lama_dan_dapat_dikosongkan_kembali(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kendaraan proyek']);
        $warna = $this->master('tipe-atribut', ['nama' => 'Warna', 'data_type' => 'string']);
        $this->attach($jenis, [['tipe_atribut_id' => $warna]])->assertOk();
        $this->receive($jenis, [['tipe_atribut_id' => $warna, 'nilai' => 'Biru khusus']]);

        $this->values($warna, [['nilai' => 'Merah']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'attribute_values_in_use')
            ->assertJsonPath('error.conflicting_count', 1)
            ->assertJsonPath('error.conflicting_values.0', 'Biru khusus');
        $this->assertDatabaseCount('aset_m_tipe_atribut_nilai', 0);

        $this->values($warna, [['nilai' => 'Biru khusus'], ['nilai' => 'Merah']])->assertOk();
        $this->receiveRaw($jenis, [['tipe_atribut_id' => $warna, 'nilai' => 'Hijau']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.atribut.'.$warna);

        $this->values($warna, [])->assertOk()->assertJsonCount(0, 'data');
        $this->receive($jenis, [['tipe_atribut_id' => $warna, 'nilai' => 'Hijau']]);
    }

    public function test_daftar_tipe_atribut_menghitung_values_dan_jenis_aset_aktif(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Generator']);
        $atribut = $this->master('tipe-atribut', ['nama' => 'Fase', 'data_type' => 'string']);
        $this->values($atribut, [['nilai' => 'Satu'], ['nilai' => 'Tiga']])->assertOk();
        $this->attach($jenis, [['tipe_atribut_id' => $atribut]])->assertOk();

        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
            ->getJson('/api/modules/management-aset/v1/tipe-atribut')
            ->assertOk()
            ->assertJsonPath('data.0.values_count', 2)
            ->assertJsonPath('data.0.jenis_aset_count', 1);
    }

    public function test_tipe_atribut_yang_masih_dipakai_tidak_dapat_diarsipkan(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $atribut = $this->master('tipe-atribut', ['nama' => 'Kapasitas', 'data_type' => 'decimal']);
        $this->attach($jenis, [['tipe_atribut_id' => $atribut]])->assertOk();

        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
            ->deleteJson('/api/modules/management-aset/v1/tipe-atribut/'.$atribut)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');
    }

    /**
     * Satu jenis aset dengan tepat satu turunan pada tiap sumbu yang dapat dihitung:
     * dua tipe atribut terpasang, satu model aset, satu aset diterima.
     *
     * @return array{jenis:string,atribut:list<string>,model:string}
     */
    private function jenisDenganTurunan(): array
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Kompresor']);
        $kapasitas = $this->master('tipe-atribut', ['nama' => 'Kapasitas', 'data_type' => 'decimal']);
        $bergaransi = $this->master('tipe-atribut', ['nama' => 'Bergaransi', 'data_type' => 'boolean']);
        $this->attach($jenis, [
            ['tipe_atribut_id' => $kapasitas],
            ['tipe_atribut_id' => $bergaransi],
        ])->assertOk();

        $pabrikan = $this->master('pabrikan-aset', ['nama' => 'Komatsu']);
        $model = $this->master('model-aset', ['nama' => 'PC200-8', 'pabrikan_aset_id' => $pabrikan, 'jenis_aset_id' => $jenis]);

        $this->receive($jenis, [
            ['tipe_atribut_id' => $kapasitas, 'nilai' => 10],
            ['tipe_atribut_id' => $bergaransi, 'nilai' => true],
        ]);

        return ['jenis' => $jenis, 'atribut' => [$kapasitas, $bergaransi], 'model' => $model];
    }

    /**
     * @param  list<string>  $permissions
     * @return TestResponse<Response>
     */
    private function detail(string $jenisId, array $permissions): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $permissions)
            ->getJson('/api/modules/management-aset/v1/jenis-aset/'.$jenisId.'/detail');
    }

    /** @param array<string, mixed> $payload */
    private function master(string $resource, array $payload): string
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor($resource))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, $payload)
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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return TestResponse<Response>
     */
    private function values(string $atributId, array $rows): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('tipe-atribut'))
            ->putJson('/api/modules/management-aset/v1/tipe-atribut/'.$atributId.'/nilai', ['rows' => $rows]);
    }

    /** @param list<array<string, mixed>> $atribut */
    private function receive(string $jenisId, array $atribut): string
    {
        $group = $this->master('group-aset', ['nama' => 'Group '.Str::random(5)]);

        return $this->terimaAset($this->tenantId, [
            'legal_entity_id' => (string) Str::ulid(),
            'nama' => 'Aset atribut uji',
            'group_aset_id' => $group, 'jenis_aset_id' => $jenisId,
            'acquired_on' => '2026-03-20', 'acquisition_value' => 1000000,
            'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
            'atribut' => $atribut,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $atribut
     * @return TestResponse<Response>
     */
    private function receiveRaw(string $jenisId, array $atribut): TestResponse
    {
        $group = $this->master('group-aset', ['nama' => 'Group '.Str::random(5)]);

        return $this->drafPenerimaan($this->tenantId, [
            'legal_entity_id' => (string) Str::ulid(),
            'nama' => 'Aset atribut uji',
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
