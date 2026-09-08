<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class MasterDataAsetTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    private int $issuedNumbers = 0;

    private int $requestCounter = 0;

    /** Http::fake menambah stub, tidak menggantinya, jadi format diubah lewat properti ini. */
    private string $numberFormat = 'NS-%06d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(function () {
            $this->issuedNumbers++;

            return Http::response(['data' => ['number' => sprintf($this->numberFormat, $this->issuedNumbers)]]);
        });
    }

    /**
     * Seluruh master klasifikasi kini datar. `jenis-aset` ikut di sini karena setelah
     * rantai diratakan ia tidak lagi punya induk.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function standaloneMasters(): array
    {
        return [
            'group aset' => ['group-aset', 'aset_m_group_aset'],
            'jenis aset' => ['jenis-aset', 'aset_m_jenis_aset'],
            'kondisi aset' => ['kondisi-aset', 'aset_m_kondisi_aset'],
            'pabrikan aset' => ['pabrikan-aset', 'aset_m_pabrikan_aset'],
            'item checklist maintenance' => ['item-checklist-maintenance', 'aset_m_item_checklist_maintenance'],
            'analisa maintenance' => ['analisa-maintenance', 'aset_m_analisa_maintenance'],
            'tipe work order' => ['tipe-work-order', 'aset_m_tipe_work_order'],
            'tingkat layanan' => ['tingkat-layanan', 'aset_m_tingkat_layanan'],
            'trade' => ['trade', 'aset_m_trade'],
            'sebab kerusakan' => ['sebab-kerusakan', 'aset_m_sebab_kerusakan'],
            'tindakan perbaikan' => ['tindakan-perbaikan', 'aset_m_tindakan_perbaikan'],
        ];
    }

    /**
     * Satu-satunya master berinduk yang tersisa, dan induk wajibnya hanya pabrikan.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function chainedMasters(): array
    {
        return [
            'model aset' => ['model-aset', 'pabrikan_aset_id'],
        ];
    }

    #[DataProvider('standaloneMasters')]
    public function test_master_mandiri_menjalankan_crud_tanpa_induk(string $resource, string $table): void
    {
        $created = $this->createRecord($resource, ['nama' => 'Data Pertama', 'keterangan' => 'Contoh'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'NS-000001')
            ->assertJsonPath('data.nama', 'Data Pertama')
            ->assertJsonPath('data.aktif', true);
        $id = $created->json('data.id');

        // Master mandiri tidak boleh membawa kolom induk apa pun.
        foreach (['group_aset_id', 'jenis_aset_id', 'pabrikan_aset_id', 'parent_id'] as $parentColumn) {
            $this->assertArrayNotHasKey($parentColumn, $created->json('data'));
        }

        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            '/api/internal/v1/number-sequences/management-aset.'.$resource.'/issue',
        ));

        $this->request($resource, 'get', '/api/v1/'.$resource.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);

        $this->request($resource, 'patch', '/api/v1/'.$resource.'/'.$id, ['nama' => 'Data Diubah', 'keterangan' => ''])
            ->assertOk()
            ->assertJsonPath('data.nama', 'Data Diubah')
            ->assertJsonPath('data.keterangan', null);

        $this->request($resource, 'delete', '/api/v1/'.$resource.'/'.$id)->assertNoContent();
        $this->assertSoftDeleted($table, ['id' => $id, 'tenant_id' => $this->tenantId]);
        $this->request($resource, 'get', '/api/v1/'.$resource)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_master_sebab_dapat_meminta_keterangan_saat_dipilih(): void
    {
        $id = $this->createRecord('sebab-kerusakan', [
            'nama' => 'Lainnya',
            'minta_keterangan' => true,
        ])->assertCreated()->assertJsonPath('data.minta_keterangan', true)->json('data.id');

        $this->assertDatabaseHas('aset_m_sebab_kerusakan', ['id' => $id, 'minta_keterangan' => true]);
    }

    #[DataProvider('chainedMasters')]
    public function test_master_berantai_wajib_membawa_induk(string $resource, string $parentColumn): void
    {
        $this->createRecord($resource, ['nama' => 'Tanpa induk'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($parentColumn);

        // Validasi induk berjalan sebelum nomor diminta, jadi Core tidak pernah dihubungi.
        Http::assertNothingSent();
    }

    public function test_model_aset_menyajikan_kedua_induknya_sekaligus(): void
    {
        $classification = $this->buildClassification();

        $model = $this->request('model-aset', 'get', '/api/v1/model-aset/'.$classification['model-aset'])->assertOk();
        $model->assertJsonPath('data.pabrikan_aset_id', $classification['pabrikan-aset']);
        $model->assertJsonPath('data.pabrikan_aset.nama', 'Komatsu');
        $model->assertJsonPath('data.jenis_aset_id', $classification['jenis-aset']);
        $model->assertJsonPath('data.jenis_aset.nama', 'Excavator 20 Ton');
    }

    public function test_model_aset_dapat_dibuat_tanpa_jenis_karena_jenis_bersifat_opsional(): void
    {
        $pabrikan = $this->createRecord('pabrikan-aset', ['nama' => 'Komatsu'])->assertCreated()->json('data.id');

        $this->createRecord('model-aset', ['nama' => 'PC200-8', 'pabrikan_aset_id' => $pabrikan])
            ->assertCreated()
            ->assertJsonPath('data.jenis_aset_id', null)
            ->assertJsonPath('data.jenis_aset', null);
    }

    public function test_daftar_model_dapat_disaring_menurut_tiap_induk_dan_gabungannya(): void
    {
        $classification = $this->buildClassification();
        $pabrikanLain = $this->createRecord('pabrikan-aset', ['nama' => 'Hitachi'])->assertCreated()->json('data.id');
        $jenisLain = $this->createRecord('jenis-aset', ['nama' => 'Excavator 30 Ton'])->assertCreated()->json('data.id');

        // Pabrikan sama, jenis berbeda: membuktikan kedua filter benar-benar independen.
        $modelJenisLain = $this->createRecord('model-aset', [
            'nama' => 'PC300-8',
            'pabrikan_aset_id' => $classification['pabrikan-aset'],
            'jenis_aset_id' => $jenisLain,
        ])->assertCreated()->json('data.id');
        $this->createRecord('model-aset', [
            'nama' => 'ZX200',
            'pabrikan_aset_id' => $pabrikanLain,
            'jenis_aset_id' => $classification['jenis-aset'],
        ])->assertCreated();

        $this->request('model-aset', 'get', '/api/v1/model-aset')->assertOk()->assertJsonPath('meta.total', 3);

        $this->request('model-aset', 'get', '/api/v1/model-aset?pabrikan_aset_id='.$classification['pabrikan-aset'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->request('model-aset', 'get', '/api/v1/model-aset?jenis_aset_id='.$classification['jenis-aset'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // Kedua filter dikirim bersamaan; server menerapkan keduanya, bukan salah satu.
        $this->request('model-aset', 'get', '/api/v1/model-aset?pabrikan_aset_id='.$classification['pabrikan-aset'].'&jenis_aset_id='.$jenisLain)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $modelJenisLain);
    }

    public function test_induk_dari_tenant_lain_ditolak_dan_tidak_menerbitkan_nomor(): void
    {
        $foreignTenant = (string) Str::ulid();
        $foreignPabrikan = $this->createRecord('pabrikan-aset', ['nama' => 'Pabrikan Tenant Lain'], tenantId: $foreignTenant)
            ->assertCreated()
            ->json('data.id');
        Http::assertSentCount(1);

        $this->createRecord('model-aset', ['nama' => 'Model Curian', 'pabrikan_aset_id' => $foreignPabrikan])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pabrikan_aset_id');

        $this->assertDatabaseCount('aset_m_model_aset', 0);
        Http::assertSentCount(1);
    }

    public function test_hanya_induk_yang_salah_tenant_yang_ditolak_bukan_seluruh_payload(): void
    {
        $classification = $this->buildClassification();
        $foreignTenant = (string) Str::ulid();
        $foreignJenis = $this->createRecord('jenis-aset', ['nama' => 'Jenis Tenant Lain'], tenantId: $foreignTenant)
            ->assertCreated()
            ->json('data.id');

        // Pabrikan sah, jenis milik tenant lain: hanya kolom jenis yang boleh disalahkan.
        $response = $this->createRecord('model-aset', [
            'nama' => 'Model Campuran',
            'pabrikan_aset_id' => $classification['pabrikan-aset'],
            'jenis_aset_id' => $foreignJenis,
        ])->assertStatus(422);
        $response->assertJsonValidationErrors('jenis_aset_id');
        $response->assertJsonMissingValidationErrors('pabrikan_aset_id');
    }

    public function test_induk_dari_tenant_lain_juga_ditolak_saat_mengubah_anak(): void
    {
        $classification = $this->buildClassification();
        $foreignTenant = (string) Str::ulid();
        $foreignPabrikan = $this->createRecord('pabrikan-aset', ['nama' => 'Pabrikan Tenant Lain'], tenantId: $foreignTenant)
            ->assertCreated()
            ->json('data.id');

        $this->request('model-aset', 'patch', '/api/v1/model-aset/'.$classification['model-aset'], ['pabrikan_aset_id' => $foreignPabrikan])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pabrikan_aset_id');

        $this->assertDatabaseHas('aset_m_model_aset', [
            'id' => $classification['model-aset'],
            'pabrikan_aset_id' => $classification['pabrikan-aset'],
        ]);
    }

    public function test_induk_yang_sudah_diarsipkan_tidak_dapat_dipilih(): void
    {
        $pabrikan = $this->createRecord('pabrikan-aset', ['nama' => 'Pabrikan Arsip'])->assertCreated()->json('data.id');
        $this->request('pabrikan-aset', 'delete', '/api/v1/pabrikan-aset/'.$pabrikan)->assertNoContent();

        $this->createRecord('model-aset', ['nama' => 'Model Baru', 'pabrikan_aset_id' => $pabrikan])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pabrikan_aset_id');
    }

    public function test_anak_dapat_dipindahkan_ke_induk_lain_pada_tenant_yang_sama(): void
    {
        $classification = $this->buildClassification();
        $pabrikanTujuan = $this->createRecord('pabrikan-aset', ['nama' => 'Pabrikan Tujuan'])->assertCreated()->json('data.id');
        $jenisTujuan = $this->createRecord('jenis-aset', ['nama' => 'Jenis Tujuan'])->assertCreated()->json('data.id');

        $this->request('model-aset', 'patch', '/api/v1/model-aset/'.$classification['model-aset'], ['pabrikan_aset_id' => $pabrikanTujuan])
            ->assertOk()
            ->assertJsonPath('data.pabrikan_aset.nama', 'Pabrikan Tujuan')
            // Memindahkan satu induk tidak boleh menggeser induk lainnya.
            ->assertJsonPath('data.jenis_aset_id', $classification['jenis-aset']);

        $this->request('model-aset', 'patch', '/api/v1/model-aset/'.$classification['model-aset'], ['jenis_aset_id' => $jenisTujuan])
            ->assertOk()
            ->assertJsonPath('data.jenis_aset.nama', 'Jenis Tujuan')
            ->assertJsonPath('data.pabrikan_aset_id', $pabrikanTujuan);
    }

    public function test_induk_tidak_dapat_diarsipkan_selama_anaknya_masih_aktif(): void
    {
        $classification = $this->buildClassification();

        // Model menggantung pada dua induk sekaligus, jadi keduanya terkunci.
        $this->request('pabrikan-aset', 'delete', '/api/v1/pabrikan-aset/'.$classification['pabrikan-aset'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');
        $this->request('jenis-aset', 'delete', '/api/v1/jenis-aset/'.$classification['jenis-aset'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');

        $this->request('model-aset', 'delete', '/api/v1/model-aset/'.$classification['model-aset'])->assertNoContent();
        $this->request('pabrikan-aset', 'delete', '/api/v1/pabrikan-aset/'.$classification['pabrikan-aset'])->assertNoContent();
        $this->request('jenis-aset', 'delete', '/api/v1/jenis-aset/'.$classification['jenis-aset'])->assertNoContent();
        // Group tidak lagi menjadi induk master mana pun, hanya aset, jadi bebas diarsipkan.
        $this->request('group-aset', 'delete', '/api/v1/group-aset/'.$classification['group-aset'])->assertNoContent();
    }

    public function test_hak_pada_satu_master_tidak_memberi_hak_pada_master_lain(): void
    {
        $classification = $this->buildClassification();
        $onlyGroupRead = $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.group-aset.read']));

        $onlyGroupRead->getJson('/api/v1/group-aset')->assertOk();
        $onlyGroupRead->getJson('/api/v1/model-aset')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $onlyGroupRead->getJson('/api/v1/jenis-aset')->assertForbidden();
        $onlyGroupRead->getJson('/api/v1/kondisi-aset')->assertForbidden();
        $onlyGroupRead->withHeader('Idempotency-Key', 'tanpa-hak-create')
            ->postJson('/api/v1/model-aset', ['nama' => 'Model', 'pabrikan_aset_id' => $classification['pabrikan-aset']])
            ->assertForbidden();
        $onlyGroupRead->patchJson('/api/v1/group-aset/'.$classification['group-aset'], ['nama' => 'Group Diubah'])->assertForbidden();
        $onlyGroupRead->deleteJson('/api/v1/group-aset/'.$classification['group-aset'])->assertForbidden();
    }

    public function test_setiap_master_memakai_reference_nomornya_sendiri(): void
    {
        $this->buildClassification();

        foreach (['group-aset', 'jenis-aset', 'pabrikan-aset', 'model-aset'] as $resource) {
            Http::assertSent(fn ($request) => $request->url() === 'http://core.test/api/internal/v1/number-sequences/management-aset.'.$resource.'/issue'
                && str_starts_with((string) $request['idempotency_key'], $resource.':')
                && $request->hasHeader('X-CoreERP-Tenant-Id', $this->tenantId));
        }
        Http::assertSentCount(4);
    }

    public function test_reference_fiskal_hanya_menampilkan_data_tenant_aktif(): void
    {
        $reference = $this->fiscalReference();
        $this->fiscalReference((string) Str::ulid(), 'tenant-lain:kelompok-1');
        DB::table('aset_m_kelompok_harta_fiskal')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'template_key' => 'test:tidak-aktif',
            'jurisdiction' => 'ID',
            'label' => 'Tidak aktif',
            'effective_from' => '2023-07-17',
            'allow_reducing_balance' => false,
            'depreciable' => true,
            'aktif' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.group-aset.read']))
            ->getJson('/api/v1/reference-data/kelompok-harta-fiskal?aktif=true&per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reference)
            ->assertJsonPath('data.0.display_label', 'Kelompok uji — berlaku 17/07/2023')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_group_aset_menyimpan_dan_menyajikan_field_finansialnya(): void
    {
        $reference = $this->fiscalReference();
        $created = $this->createRecord('group-aset', [
            'nama' => 'Bangunan',
            'kelompok_harta_fiskal_id' => $reference,
            'property_type' => 'fixed_asset',
            'capitalization_threshold' => 1000,
        ])->assertCreated();

        $created->assertJsonPath('data.kelompok_harta_fiskal_id', $reference);
        $created->assertJsonPath('data.property_type', 'fixed_asset');
        $created->assertJsonPath('data.capitalization_threshold', '1000.00');

        $this->request('group-aset', 'patch', '/api/v1/group-aset/'.$created->json('data.id'), ['capitalization_threshold' => 2500])
            ->assertOk()
            ->assertJsonPath('data.capitalization_threshold', '2500.00')
            // Field lain tidak ikut tergeser saat satu field diubah.
            ->assertJsonPath('data.kelompok_harta_fiskal_id', $reference);
    }

    public function test_reference_fiskal_tidak_boleh_dipakai_lintas_tenant_dan_field_lama_ditolak(): void
    {
        $foreignReference = $this->fiscalReference((string) Str::ulid(), 'tenant-lain:kelompok-1');
        $this->createRecord('group-aset', ['nama' => 'Salah', 'kelompok_harta_fiskal_id' => $foreignReference])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kelompok_harta_fiskal_id');

        $this->createRecord('group-aset', ['nama' => 'Salah', 'tipe_harta' => 'kelompok_9'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipe_harta');

        // Lapisan pembukuan pindah ke buku penyusutan; kiriman lama ditolak, bukan
        // diterima diam-diam, supaya konfigurator tahu tempatnya sudah berubah.
        $this->createRecord('group-aset', ['nama' => 'Salah', 'posting_layers' => ['current']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('posting_layers');
    }

    /**
     * Sifat harta dibuang dari group; akun ditentukan posting profile milik Finance.
     * Client lama yang masih mengirim `major_type` harus gagal keras, karena diterima
     * diam-diam berarti klasifikasinya hilang tanpa jejak.
     */
    public function test_field_sifat_harta_ditolak_dan_property_type_divalidasi(): void
    {
        $this->createRecord('group-aset', ['nama' => 'Salah', 'major_type' => 'tangible'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('major_type');

        $this->createRecord('group-aset', ['nama' => 'Salah', 'property_type' => 'ekstrakomptabel'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('property_type');
    }

    /** Lokasi bawaan harus milik tenant yang sama; ULID asing tidak boleh lolos. */
    public function test_lokasi_bawaan_group_tidak_boleh_lintas_tenant(): void
    {
        $this->createRecord('group-aset', ['nama' => 'Salah', 'asset_location_id' => (string) Str::ulid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('asset_location_id');
    }

    /**
     * Kolom bercast `decimal:2` dibaca kembali sebagai "1000.00" sementara payload retry
     * membawa angka 1000. Tanpa normalisasi lewat cast model, perbandingan strict di
     * replay() menuduh retry yang sah sebagai idempotency_conflict.
     */
    public function test_retry_kolom_desimal_tetap_direplay_bukan_dianggap_konflik(): void
    {
        $key = 'ambang-kapitalisasi';
        $payload = ['nama' => 'Mesin', 'capitalization_threshold' => 1000];
        $first = $this->postWithKey('group-aset', $payload, $key)->assertCreated();

        $this->postWithKey('group-aset', $payload, $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        // Nilai yang benar-benar berbeda tetap harus ditolak sebagai konflik.
        $this->postWithKey('group-aset', ['nama' => 'Mesin', 'capitalization_threshold' => 2000], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        Http::assertSentCount(1);
    }

    public function test_kode_dari_klien_diabaikan_dan_selalu_berasal_dari_core(): void
    {
        $this->createRecord('kondisi-aset', ['nama' => 'Baik', 'kode' => 'DIPAKSA-01'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'NS-000001');
    }

    public function test_daftar_master_tidak_pernah_menampilkan_data_tenant_lain(): void
    {
        $this->createRecord('kondisi-aset', ['nama' => 'Baik'])->assertCreated();
        $foreignTenant = (string) Str::ulid();
        $foreign = $this->createRecord('kondisi-aset', ['nama' => 'Rusak'], tenantId: $foreignTenant)
            ->assertCreated()
            ->json('data.id');

        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nama', 'Baik');
        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset/'.$foreign)->assertNotFound();
    }

    public function test_kunci_pembuatan_milik_record_yang_diarsipkan_tetap_direplay(): void
    {
        // Unique (tenant_id, creation_key) tetap mengikat record yang sudah diarsipkan.
        // Retry dengan kunci itu harus mengembalikan record yang sama, bukan 500.
        $key = 'kunci-dipakai-ulang';
        $created = $this->postWithKey('kondisi-aset', ['nama' => 'Baik'], $key)->assertCreated();
        $this->request('kondisi-aset', 'delete', '/api/v1/kondisi-aset/'.$created->json('data.id'))->assertNoContent();

        $this->postWithKey('kondisi-aset', ['nama' => 'Baik'], $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $created->json('data.id'));

        Http::assertSentCount(1);
        $this->assertDatabaseCount('aset_m_kondisi_aset', 1);
    }

    public function test_retry_tetap_direplay_walau_aktif_dikirim_sebagai_angka(): void
    {
        $key = 'aktif-sebagai-angka';
        $first = $this->postWithKey('kondisi-aset', ['nama' => 'Baik', 'aktif' => 1], $key)->assertCreated();

        $this->postWithKey('kondisi-aset', ['nama' => 'Baik', 'aktif' => 1], $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_filter_daftar_menghormati_nilai_tepi_yang_sah(): void
    {
        $this->createRecord('kondisi-aset', ['nama' => 'Baik', 'keterangan' => '0'])
            ->assertCreated()
            ->assertJsonPath('data.keterangan', '0');
        $this->createRecord('kondisi-aset', ['nama' => 'Rusak', 'aktif' => false])->assertCreated();

        // `?aktif=` kosong berarti tanpa filter, bukan "hanya yang tidak aktif".
        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset?aktif=')->assertOk()->assertJsonPath('meta.total', 2);
        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset?aktif=false')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_pencarian_angka_nol_tetap_dipakai_sebagai_kata_kunci(): void
    {
        // Nomor sengaja tanpa digit nol supaya yang tersaring benar-benar berasal dari nama.
        $this->numberFormat = 'PB-9999%d';
        $this->createRecord('pabrikan-aset', ['nama' => 'Merek 0'])->assertCreated();
        $this->createRecord('pabrikan-aset', ['nama' => 'Merek Lain'])->assertCreated();

        $this->request('pabrikan-aset', 'get', '/api/v1/pabrikan-aset?q=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nama', 'Merek 0');
    }

    public function test_kunci_pembuatan_dibatasi_agar_selalu_muat_pada_batas_core(): void
    {
        // Slug terpanjang (26) + ':' + kunci harus tetap <= 160 karakter di sisi Core.
        $this->postWithKey('item-checklist-maintenance', ['nama' => 'Ban depan'], str_repeat('k', 133))
            ->assertCreated();
        $this->postWithKey('item-checklist-maintenance', ['nama' => 'Ban belakang'], str_repeat('k', 134))
            ->assertStatus(422);

        Http::assertSent(fn ($request) => strlen((string) $request['idempotency_key']) <= 160);
    }

    public function test_database_menegakkan_batas_tenant_pada_foreign_key_master(): void
    {
        $classification = $this->buildClassification();
        $pabrikan = $classification['pabrikan-aset'];

        // Menulis langsung ke tabel, melewati validasi aplikasi, agar yang diuji adalah
        // foreign key gabungan (tenant_id, pabrikan_aset_id) -> (tenant_id, id).
        $this->assertTrue($this->insertModelDirectly($this->tenantId, $pabrikan));
        $this->assertDatabaseCount('aset_m_model_aset', 2);

        $this->assertFalse($this->insertModelDirectly((string) Str::ulid(), $pabrikan), 'induk milik tenant lain harus ditolak database');
        $this->assertFalse($this->insertModelDirectly($this->tenantId, (string) Str::ulid()), 'induk yang tidak ada harus ditolak database');
        $this->assertDatabaseCount('aset_m_model_aset', 2);

        // Arsip adalah soft delete sehingga referensi tidak pernah terputus; hard delete tetap ditahan.
        $this->assertFalse($this->hardDelete('aset_m_pabrikan_aset', $pabrikan), 'hard delete induk yang masih direferensikan harus ditahan');
        $this->assertDatabaseHas('aset_m_pabrikan_aset', ['id' => $pabrikan, 'deleted_at' => null]);
    }

    /**
     * Kode dan creation_key selalu unik per pemanggilan agar kegagalan hanya dapat
     * berasal dari foreign key.
     *
     * Percobaan dibungkus transaksi bersarang supaya Laravel memasang SAVEPOINT.
     * PostgreSQL membatalkan seluruh transaksi begitu satu statement gagal, sehingga
     * tanpa savepoint percobaan berikutnya kena "current transaction is aborted" dan
     * bukan pelanggaran foreign key yang sedang diuji. SQLite tidak berperilaku begitu,
     * jadi kekurangan ini hanya terlihat pada mesin yang sebenarnya dipakai produksi.
     */
    private function insertModelDirectly(string $tenantId, string $parentId): bool
    {
        $suffix = ++$this->requestCounter;

        try {
            return DB::transaction(fn (): bool => DB::table('aset_m_model_aset')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'creation_key' => 'langsung-'.$suffix,
                'pabrikan_aset_id' => $parentId,
                'kode' => sprintf('MDLA-L%05d', $suffix),
                'nama' => 'Model langsung',
                'aktif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (QueryException) {
            return false;
        }
    }

    private function hardDelete(string $table, string $id): bool
    {
        try {
            DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Klasifikasi lengkap pada tenant aktif. Group, jenis, dan pabrikan dibuat tanpa
     * urutan yang mengikat; hanya model yang menunggu kedua induknya ada.
     *
     * @return array<string, string>
     */
    private function buildClassification(): array
    {
        $group = $this->createRecord('group-aset', ['nama' => 'Alat Berat'])
            ->assertCreated()->json('data.id');
        $jenis = $this->createRecord('jenis-aset', ['nama' => 'Excavator 20 Ton'])
            ->assertCreated()->json('data.id');
        $pabrikan = $this->createRecord('pabrikan-aset', ['nama' => 'Komatsu'])
            ->assertCreated()->json('data.id');
        $model = $this->createRecord('model-aset', [
            'nama' => 'PC200-8',
            'pabrikan_aset_id' => $pabrikan,
            'jenis_aset_id' => $jenis,
        ])->assertCreated()->json('data.id');

        return [
            'group-aset' => $group,
            'jenis-aset' => $jenis,
            'pabrikan-aset' => $pabrikan,
            'model-aset' => $model,
        ];
    }

    private function fiscalReference(?string $tenantId = null, string $templateKey = 'test:kelompok-1'): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_kelompok_harta_fiskal')->insert([
            'id' => $id,
            'tenant_id' => $tenantId ?? $this->tenantId,
            'template_key' => $templateKey,
            'jurisdiction' => 'ID',
            'label' => 'Kelompok uji',
            'regulation_reference' => 'Referensi uji',
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

    /** @param array<string, mixed> $payload */
    private function createRecord(string $resource, array $payload, ?string $tenantId = null): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($tenantId ?? $this->tenantId, $this->permissionsFor($resource)))
            ->withHeader('Idempotency-Key', $this->creationKeyFor($resource))
            ->postJson('/api/v1/'.$resource, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function postWithKey(string $resource, array $payload, string $key): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor($resource)))
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/'.$resource, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $resource, string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor($resource)))
            ->json(strtoupper($method), $uri, $payload);
    }

    /** Kunci unik per percobaan pembuatan, tetap diawali slug resource. */
    private function creationKeyFor(string $resource): string
    {
        return $resource.'-'.++$this->requestCounter;
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(
            fn (string $action): string => 'management-aset.'.$resource.'.'.$action,
            ['read', 'create', 'update', 'archive'],
        );
    }
}
