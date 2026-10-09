<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Access\Models\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Reporting\PenyediaLaporan;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Monitoring aset: pemeriksaan fisik satu lokasi, disusun sebagai draf lalu diselesaikan.
 *
 * Yang dijaga di sini: menyelesaikan pemeriksaan tidak pernah mengubah register aset, hasil tiap
 * baris dihitung dari status siklus hidup aset dan bukan dipilih, temuan dibekukan saat selesai, dan
 * dokumen selesai terkunci.
 */
class AssetMonitoringTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private const URL = '/api/modules/management-aset/v1/monitoring-aset';

    private const ALL = [
        'management-aset.monitoring-aset.read',
        'management-aset.monitoring-aset.create',
        'management-aset.monitoring-aset.update',
        'management-aset.monitoring-aset.archive',
        'management-aset.monitoring-aset.complete',
    ];

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    private string $groupId = '';

    private string $jenisId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->unitId = (string) Str::ulid();
        Http::preventStrayRequests();
    }

    public function test_completing_freezes_findings_and_never_touches_the_register(): void
    {
        $gudang = $this->location('Gudang pusat');
        $lain = $this->location('Ruang rapat');
        $laptop = $this->receive('Laptop', $gudang);
        $monitor = $this->receive('Monitor', $gudang);
        $this->receive('Proyektor', $lain);
        $register = $this->registerRows();
        $placements = DB::table('aset_tr_penempatan_aset')->count();

        $id = $this->draft($gudang);
        $this->fill($id)->assertOk()->assertJsonPath('meta.ditambahkan', 2)->assertJsonCount(2, 'data.details');
        $this->save($id, $gudang, [
            ['aset_id' => $laptop, 'ada' => true, 'keterangan' => 'Di meja resepsionis'],
            ['aset_id' => $monitor, 'ada' => false],
        ])->assertOk();

        $done = $this->complete($id)->assertOk()->assertJsonPath('data.status', 'selesai');

        $this->assertNotNull($done->json('data.diselesaikan_pada'));
        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', [
            'monitoring_aset_id' => $id, 'aset_id' => $laptop, 'ada' => true, 'hasil' => 'sesuai',
            'sistem_lifecycle_state' => 'received', 'sistem_lokasi_id' => $gudang, 'sistem_org_unit_id' => $this->unitId,
            'nilai_perolehan' => '1200000.00', 'akumulasi_penyusutan' => '0.00', 'nilai_buku' => '1200000.00',
        ]);
        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', [
            'monitoring_aset_id' => $id, 'aset_id' => $monitor, 'ada' => false, 'hasil' => 'tidak_sesuai',
        ]);
        // Temuan "tidak ada" tidak menghentikan aset, tidak memindahkannya, dan tidak menambah riwayat
        // penempatan: perubahan register tetap dokumen mutasi atau dekomisioning.
        $this->assertEquals($register, $this->registerRows());
        $this->assertSame($placements, DB::table('aset_tr_penempatan_aset')->count());
    }

    /**
     * Buku penyusutan bawaan di pengaturan aset tetap (Default Depr. Book BC) menentukan buku yang
     * nilainya dibekukan, mendahului aturan "buku komersial berkode paling awal".
     */
    public function test_frozen_value_comes_from_the_default_depreciation_book_when_set(): void
    {
        $gudang = $this->location('Gudang buku');
        $laptop = $this->receive('Laptop fiskal', $gudang);
        $komersial = (array) DB::table('aset_tr_buku_aset')->where('aset_id', $laptop)->first();
        $fiskal = (string) Str::ulid();
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $fiskal, 'tenant_id' => $this->tenantId, 'creation_key' => 'fiskal-'.$fiskal, 'kode' => 'FISKAL',
            'nama' => 'Buku fiskal', 'aktif' => true, 'posting_layer' => 'tax', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aset_tr_buku_aset')->insert([
            ...array_diff_key($komersial, array_flip(['version', 'created_by_user_id', 'updated_by_user_id'])),
            'id' => (string) Str::ulid(), 'buku_id' => $fiskal, 'book_code' => 'FISKAL',
            'acquisition_value' => '1200000.00', 'accumulated_depreciation' => '200000.00', 'net_book_value' => '1000000.00',
        ]);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.fixed-asset-parameters.update'])
            ->putJson('/api/modules/management-aset/v1/pengaturan-aset-tetap', ['buku_penyusutan_bawaan_id' => $fiskal, 'version' => 0])
            ->assertOk();

        $id = $this->draft($gudang);
        $this->save($id, $gudang, [['aset_id' => $laptop, 'ada' => true]])->assertOk();
        $this->complete($id)->assertOk();

        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', [
            'monitoring_aset_id' => $id, 'aset_id' => $laptop,
            'akumulasi_penyusutan' => '200000.00', 'nilai_buku' => '1000000.00',
        ]);
    }

    public function test_result_is_computed_from_the_lifecycle_state_including_the_qa_example(): void
    {
        $gudang = $this->location('Gudang arsip');
        $aktifAda = $this->receive('Aktif ada', $gudang);
        $aktifHilang = $this->receive('Aktif hilang', $gudang);
        $musnahAda = $this->receive('Dimusnahkan masih ada', $gudang);
        $henti = $this->receive('Didekomisioning sudah tidak ada', $gudang);
        DB::table('aset_tr_aset')->where('id', $musnahAda)->update(['lifecycle_state' => 'disposed']);
        DB::table('aset_tr_aset')->where('id', $henti)->update(['lifecycle_state' => 'decommissioned']);

        $id = $this->draft($gudang);
        // Isi otomatis ikut membawa aset yang sudah dilepas dan didekomisioning.
        $this->fill($id)->assertOk()->assertJsonPath('meta.ditambahkan', 4);
        $this->save($id, $gudang, [
            ['aset_id' => $aktifAda, 'ada' => true],
            ['aset_id' => $aktifHilang, 'ada' => false],
            ['aset_id' => $musnahAda, 'ada' => true, 'keterangan' => 'Belum dimusnahkan'],
            ['aset_id' => $henti, 'ada' => false],
        ])->assertOk();

        // Selama draf hasilnya dihitung langsung dari status aset sekarang.
        $draft = $this->linesByAsset($id);
        $this->assertSame(
            ['sesuai', 'tidak_sesuai', 'tidak_sesuai', 'sesuai'],
            [$draft[$aktifAda]['hasil'], $draft[$aktifHilang]['hasil'], $draft[$musnahAda]['hasil'], $draft[$henti]['hasil']],
        );
        $this->assertSame('Dilepas', $draft[$musnahAda]['sistem_lifecycle_label']);
        // Semua aset tercatat di lokasi yang diperiksa: tidak ada keterangan lokasi otomatis, dan
        // keterangan pemeriksa tetap apa adanya.
        $this->assertSame(['Belum dimusnahkan', null], [$draft[$musnahAda]['keterangan'], $draft[$aktifAda]['keterangan']]);
        $this->show($id)->assertJsonPath('data.jumlah_tidak_sesuai', 2)->assertJsonPath('data.jumlah_belum_diperiksa', 0);

        $this->complete($id)->assertOk();
        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', [
            'aset_id' => $musnahAda, 'ada' => true, 'hasil' => 'tidak_sesuai', 'sistem_lifecycle_state' => 'disposed', 'keterangan' => 'Belum dimusnahkan',
        ]);

        // Sesudah selesai, perubahan register tidak lagi mengubah temuan yang dibekukan.
        DB::table('aset_tr_aset')->where('id', $aktifHilang)->update(['lifecycle_state' => 'decommissioned']);
        $frozen = $this->linesByAsset($id);
        $this->assertSame('tidak_sesuai', $frozen[$aktifHilang]['hasil']);
        $this->assertSame('received', $frozen[$aktifHilang]['sistem_lifecycle_state']);
    }

    public function test_asset_found_but_registered_elsewhere_is_a_mismatch_with_an_automatic_note(): void
    {
        $diperiksa = $this->location('Ruang rapat lantai 2');
        $tercatat = $this->location('Gudang pusat');
        $pindahan = $this->receive('Proyektor pindahan', $tercatat);
        $diedit = $this->receive('Kursi pindahan', $tercatat);
        $hilangAktif = $this->receive('Kamera tercatat di gudang', $tercatat);
        $hilangHenti = $this->receive('Kipas sudah dihentikan', $tercatat);
        $tanpaLokasi = $this->receive('Papan tanpa lokasi', $tercatat);
        DB::table('aset_tr_aset')->where('id', $hilangHenti)->update(['lifecycle_state' => 'decommissioned']);
        DB::table('aset_tr_aset')->where('id', $tanpaLokasi)->update(['lokasi_aset_id' => null]);

        $id = $this->draft($diperiksa, ['details' => [
            ['aset_id' => $pindahan, 'ada' => true],
            ['aset_id' => $diedit, 'ada' => true, 'keterangan' => 'Dipinjam untuk rapat'],
            ['aset_id' => $hilangAktif, 'ada' => false],
            ['aset_id' => $hilangHenti, 'ada' => false],
            ['aset_id' => $tanpaLokasi, 'ada' => true],
        ]]);

        $draft = $this->linesByAsset($id);
        // Ditemukan di lokasi yang diperiksa padahal tercatat di tempat lain: tidak sesuai, dengan nama
        // lokasinya — bukan ULID — sebagai keterangan otomatis. Keterangan pemeriksa tidak ditimpa.
        $this->assertSame(['tidak_sesuai', 'Tercatat di Gudang pusat'], [$draft[$pindahan]['hasil'], $draft[$pindahan]['keterangan']]);
        $this->assertSame(['tidak_sesuai', 'Dipinjam untuk rapat'], [$draft[$diedit]['hasil'], $draft[$diedit]['keterangan']]);
        $this->assertSame(['tidak_sesuai', 'Belum tercatat di lokasi mana pun'], [$draft[$tanpaLokasi]['hasil'], $draft[$tanpaLokasi]['keterangan']]);
        // Yang tidak ditemukan tetap mengikuti status siklus hidupnya, di mana pun ia tercatat.
        $this->assertSame(['tidak_sesuai', null], [$draft[$hilangAktif]['hasil'], $draft[$hilangAktif]['keterangan']]);
        $this->assertSame(['sesuai', null], [$draft[$hilangHenti]['hasil'], $draft[$hilangHenti]['keterangan']]);
        $this->show($id)->assertJsonPath('data.jumlah_tidak_sesuai', 4);

        // Pemeriksa boleh mengganti keterangan otomatis.
        $this->save($id, $diperiksa, [
            ['aset_id' => $pindahan, 'ada' => true, 'keterangan' => 'Dipindah tanpa berita acara'],
            ['aset_id' => $diedit, 'ada' => true, 'keterangan' => 'Dipinjam untuk rapat'],
            ['aset_id' => $hilangAktif, 'ada' => false],
            ['aset_id' => $hilangHenti, 'ada' => false],
            ['aset_id' => $tanpaLokasi, 'ada' => true, 'keterangan' => 'Belum tercatat di lokasi mana pun'],
        ])->assertOk();
        $this->complete($id)->assertOk()->assertJsonPath('data.jumlah_tidak_sesuai', 4);

        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', [
            'monitoring_aset_id' => $id, 'aset_id' => $pindahan, 'hasil' => 'tidak_sesuai',
            'sistem_lokasi_id' => $tercatat, 'keterangan' => 'Dipindah tanpa berita acara',
        ]);
        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', ['monitoring_aset_id' => $id, 'aset_id' => $hilangHenti, 'hasil' => 'sesuai']);
        // Temuan tidak memindahkan aset.
        $this->assertSame($tercatat, (string) DB::table('aset_tr_aset')->where('id', $pindahan)->value('lokasi_aset_id'));
    }

    public function test_fill_follows_unit_and_person_on_the_header_and_skips_assets_already_listed(): void
    {
        $gudang = $this->location('Gudang unit');
        $unitLain = (string) Str::ulid();
        $milikEva = $this->receive('Milik Eva', $gudang, ['custodian_user_id' => 'user-eva']);
        $this->receive('Milik orang lain', $gudang, ['custodian_user_id' => 'user-lain']);
        $this->receive('Unit lain', $gudang, ['usage_org_unit_id' => $unitLain, 'custodian_user_id' => 'user-eva']);

        $id = $this->draft($gudang, ['penanggung_jawab_user_id' => 'user-eva']);

        $this->fill($id)->assertOk()->assertJsonPath('meta.ditambahkan', 1)->assertJsonPath('data.details.0.aset_id', $milikEva);
        // Menekannya lagi tidak menggandakan baris.
        $this->fill($id)->assertOk()->assertJsonPath('meta.ditambahkan', 0)->assertJsonCount(1, 'data.details');
    }

    public function test_completed_document_is_locked(): void
    {
        $gudang = $this->location('Gudang kunci');
        $aset = $this->receive('Kursi', $gudang);
        $id = $this->draft($gudang, ['details' => [['aset_id' => $aset, 'ada' => true]]]);
        $this->complete($id)->assertOk();
        $version = $this->version($id);

        $user = $this->sebagaiPengguna($this->tenantId, self::ALL);
        $user->patchJson(self::URL.'/'.$id, [...$this->header($gudang), 'details' => [['aset_id' => $aset, 'ada' => false]], 'version' => $version])->assertStatus(422);
        $user->postJson(self::URL.'/'.$id.'/isi-otomatis', ['version' => $version])->assertStatus(422);
        $user->postJson(self::URL.'/'.$id.'/selesaikan', ['version' => $version])->assertStatus(422);
        $user->deleteJson(self::URL.'/'.$id, ['version' => $version])->assertStatus(422);

        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', ['monitoring_aset_id' => $id, 'aset_id' => $aset, 'ada' => true, 'deleted_at' => null]);
    }

    public function test_every_line_must_be_checked_before_completion(): void
    {
        $gudang = $this->location('Gudang belum');
        $pertama = $this->receive('Meja', $gudang);
        $kedua = $this->receive('Lemari', $gudang);
        $id = $this->draft($gudang, ['details' => [['aset_id' => $pertama, 'ada' => true], ['aset_id' => $kedua]]]);

        $this->complete($id)->assertStatus(422)->assertJsonPath('errors.details.0', 'Keberadaan fisik aset pada baris 2 belum diisi.');
        $this->assertDatabaseHas('aset_tr_monitoring_aset', ['id' => $id, 'status' => 'draft']);

        $kosong = $this->draft($gudang);
        $this->complete($kosong)->assertStatus(422);
    }

    public function test_removed_lines_are_archived_and_their_numbers_are_not_reused(): void
    {
        $gudang = $this->location('Gudang baris');
        [$a, $b, $c] = [$this->receive('A', $gudang), $this->receive('B', $gudang), $this->receive('C', $gudang)];
        $id = $this->draft($gudang, ['details' => [['aset_id' => $a], ['aset_id' => $b]]]);

        $this->save($id, $gudang, [['aset_id' => $a, 'ada' => true], ['aset_id' => $c]])->assertOk();

        $this->assertSoftDeleted('aset_tr_monitoring_aset_details', ['monitoring_aset_id' => $id, 'aset_id' => $b, 'line_number' => 2]);
        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', ['monitoring_aset_id' => $id, 'aset_id' => $a, 'line_number' => 1, 'ada' => true]);
        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', ['monitoring_aset_id' => $id, 'aset_id' => $c, 'line_number' => 3]);

        // Aset yang dikeluarkan boleh masuk lagi, dengan nomor baris baru.
        $this->save($id, $gudang, [['aset_id' => $a, 'ada' => true], ['aset_id' => $c], ['aset_id' => $b]])->assertOk();
        $this->assertDatabaseHas('aset_tr_monitoring_aset_details', ['monitoring_aset_id' => $id, 'aset_id' => $b, 'line_number' => 4, 'deleted_at' => null]);

        $this->create($gudang, ['details' => [['aset_id' => $a], ['aset_id' => $a]]])->assertStatus(422);
    }

    public function test_number_is_issued_per_legal_entity_and_create_is_idempotent(): void
    {
        $entitasLain = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->pastikanOrganisasiAda($this->tenantId, $entitasLain, 'legal_entity');
        DB::table('tenant_number_sequences')
            ->where('tenant_id', $this->tenantId)
            ->where('reference_id', DB::table('app_number_sequence_references')->where('code', 'management-aset.monitoring-aset')->value('id'))
            ->update(['scope_type' => 'legal_entity']);
        $gudang = $this->location('Gudang nomor');

        $key = 'monitoring-'.Str::ulid();
        $pertama = $this->create($gudang, [], $key)->assertCreated();
        $ulang = $this->create($gudang, [], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $lain = $this->create($gudang, ['legal_entity_id' => $entitasLain])->assertCreated();
        $kedua = $this->create($gudang)->assertCreated();

        $this->assertSame($pertama->json('data.id'), $ulang->json('data.id'));
        $this->assertMatchesRegularExpression('/^'.preg_quote($this->awalanNomor('management-aset.monitoring-aset'), '/').'/', (string) $pertama->json('data.kode'));
        // Dua entitas legal, dua counter: nomor pertama masing-masing sama, nomor kedua entitas pertama tidak.
        $this->assertSame($pertama->json('data.kode'), $lain->json('data.kode'));
        $this->assertNotSame($pertama->json('data.kode'), $kedua->json('data.kode'));
        $this->assertSame(3, DB::table('aset_tr_monitoring_aset')->count());
    }

    public function test_stale_or_missing_version_is_rejected(): void
    {
        $gudang = $this->location('Gudang versi');
        $aset = $this->receive('Printer', $gudang);
        $id = $this->draft($gudang);
        $user = $this->sebagaiPengguna($this->tenantId, self::ALL);

        $user->patchJson(self::URL.'/'.$id, [...$this->header($gudang), 'keterangan' => 'Pertama', 'details' => [], 'version' => 1])->assertOk();
        $user->patchJson(self::URL.'/'.$id, [...$this->header($gudang), 'keterangan' => 'Kedua', 'details' => [], 'version' => 1])
            ->assertConflict()->assertJsonPath('error.code', 'stale_version')->assertJsonPath('error.message', RowVersion::STALE_MESSAGE);
        $user->postJson(self::URL.'/'.$id.'/isi-otomatis', ['version' => 1])->assertConflict();
        $user->postJson(self::URL.'/'.$id.'/selesaikan', ['version' => 1])->assertConflict();
        $user->postJson(self::URL.'/'.$id.'/selesaikan')->assertStatus(428);
        $user->deleteJson(self::URL.'/'.$id)->assertStatus(428);

        $this->assertDatabaseHas('aset_tr_monitoring_aset', ['id' => $id, 'keterangan' => 'Pertama', 'status' => 'draft', 'deleted_at' => null]);
        $this->assertDatabaseMissing('aset_tr_monitoring_aset_details', ['aset_id' => $aset]);
        $this->show($id)->assertHeader('ETag', RowVersion::etag($this->version($id)));
    }

    public function test_archived_draft_disappears_from_list_and_detail(): void
    {
        $gudang = $this->location('Gudang arsip draf');
        $id = $this->draft($gudang);

        $this->sebagaiPengguna($this->tenantId, self::ALL)->deleteJson(self::URL.'/'.$id, ['version' => $this->version($id)])->assertNoContent();

        $this->assertSoftDeleted('aset_tr_monitoring_aset', ['id' => $id]);
        $this->show($id)->assertNotFound();
        $this->sebagaiPengguna($this->tenantId, self::ALL)->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_tenant_cannot_read_or_change_the_document(): void
    {
        $gudang = $this->location('Gudang tenant');
        $id = $this->draft($gudang);
        $tenantLain = $this->buatTenantUji();

        $asing = $this->sebagaiPengguna($tenantLain, self::ALL);
        $asing->getJson(self::URL.'/'.$id)->assertNotFound();
        $asing->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $asing->patchJson(self::URL.'/'.$id, [...$this->header($gudang), 'details' => [], 'version' => 1])->assertNotFound();
        $asing->postJson(self::URL.'/'.$id.'/isi-otomatis', ['version' => 1])->assertNotFound();
        $asing->postJson(self::URL.'/'.$id.'/selesaikan', ['version' => 1])->assertNotFound();

        $this->assertDatabaseHas('aset_tr_monitoring_aset', ['id' => $id, 'version' => 1, 'status' => 'draft']);
    }

    public function test_organization_policy_limits_documents_and_assets_to_the_granted_units(): void
    {
        $unitLain = (string) Str::ulid();
        $gudang = $this->location('Gudang bersama');
        $milikSendiri = $this->receive('Milik unit sendiri', $gudang);
        $milikLain = $this->receive('Milik unit lain', $gudang, ['usage_org_unit_id' => $unitLain]);
        $dokumenLain = $this->draft($gudang, ['responsible_org_unit_id' => $unitLain]);
        $tanpaUnit = $this->draft($gudang, ['responsible_org_unit_id' => null]);
        $terbatas = fn () => $this->sebagaiPenggunaBernama('Pemeriksa unit', $this->tenantId, self::ALL, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->unitId,
        ]]);

        $terbatas()->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $terbatas()->getJson(self::URL.'/'.$dokumenLain)->assertNotFound();
        $terbatas()->getJson(self::URL.'/'.$tanpaUnit)->assertNotFound();
        $terbatas()->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())
            ->postJson(self::URL, [...$this->header($gudang), 'responsible_org_unit_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('responsible_org_unit_id');
        $terbatas()->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())
            ->postJson(self::URL, [...$this->header($gudang), 'responsible_org_unit_id' => $unitLain])
            ->assertForbidden();
        $terbatas()->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())
            ->postJson(self::URL, [...$this->header($gudang), 'details' => [['aset_id' => $milikLain]]])
            ->assertStatus(422);

        $sendiri = (string) $terbatas()->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())
            ->postJson(self::URL, $this->header($gudang))->assertCreated()->json('data.id');
        // Isi otomatis hanya membawa aset dalam jangkauan pemeriksa, walau keduanya di lokasi yang sama.
        $this->assertSame([$milikSendiri], array_column(
            $terbatas()->postJson(self::URL.'/'.$sendiri.'/isi-otomatis', ['version' => 1])->assertOk()->json('data.details'),
            'aset_id',
        ));
        $terbatas()->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sendiri);
    }

    public function test_the_real_duty_from_the_manifest_grants_the_whole_check(): void
    {
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']), Artisan::output());
        $this->assertEqualsCanonicalizing(
            ['management-aset.monitoring-aset.view', 'management-aset.monitoring-aset.maintain', 'management-aset.monitoring-aset.retire', 'management-aset.monitoring-aset.complete-check'],
            DB::table('security_duty_privileges')->where('duty_code', 'management-aset.monitoring-aset.manage')->pluck('privilege_code')->all(),
        );
        $gudang = $this->location('Gudang duty');
        $aset = $this->receive('Kulkas', $gudang);

        $this->withDuty(['management-aset.monitoring-aset.manage']);
        $id = (string) $this->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())
            ->postJson(self::URL, $this->header($gudang))->assertCreated()->json('data.id');
        $version = (int) $this->postJson(self::URL.'/'.$id.'/isi-otomatis', ['version' => 1])->assertOk()->json('data.version');
        $version = (int) $this->patchJson(self::URL.'/'.$id, [...$this->header($gudang), 'details' => [['aset_id' => $aset, 'ada' => true]], 'version' => $version])->assertOk()->json('data.version');
        $this->postJson(self::URL.'/'.$id.'/selesaikan', ['version' => $version])->assertOk()->assertJsonPath('data.status', 'selesai');
        $draf = (string) $this->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())
            ->postJson(self::URL, $this->header($gudang))->assertCreated()->json('data.id');
        $this->deleteJson(self::URL.'/'.$draf, ['version' => 1])->assertNoContent();

        // Duty lain di module yang sama bukan hak atas monitoring.
        $this->withDuty(['management-aset.aset.manage']);
        $this->getJson(self::URL)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())->postJson(self::URL, $this->header($gudang))->assertForbidden();
    }

    /**
     * Keputusan 30 September 2026: duty Pantau aset membawa hak baca lokasi, kondisi, dan register aset
     * untuk isian manual, tanpa satu pun hak tulis atas ketiganya.
     */
    public function test_monitoring_duty_alone_loads_the_manual_pickers_and_grants_no_write(): void
    {
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']), Artisan::output());
        $gudang = $this->location('Gudang pemilih');
        $baik = $this->master('kondisi-aset', ['nama' => 'Baik']);
        $aset = $this->receive('Televisi', $gudang);
        $asetVersion = (int) DB::table('aset_tr_aset')->where('id', $aset)->value('version');

        // Seluruh permission yang dijangkau duty ini lewat katalog sungguhan: hanya baca, selain
        // permission monitoring sendiri.
        $reachable = DB::table('security_duty_privileges as dp')
            ->join('security_privilege_permissions as pp', 'pp.privilege_code', '=', 'dp.privilege_code')
            ->join('permissions as p', 'p.code', '=', 'pp.permission_code')
            ->where('dp.duty_code', 'management-aset.monitoring-aset.manage')
            ->where('p.code', 'not like', 'management-aset.monitoring-aset.%')
            ->pluck('p.access_level', 'p.code')->all();
        $this->assertEqualsCanonicalizing(
            ['management-aset.lokasi-aset.read' => 'read', 'management-aset.kondisi-aset.read' => 'read', 'management-aset.aset.read' => 'read'],
            $reachable,
        );

        $this->withDuty(['management-aset.monitoring-aset.manage']);
        $this->getJson('/api/modules/management-aset/v1/lokasi-aset?per_page=100&aktif=true')->assertOk()->assertJsonFragment(['id' => $gudang]);
        $this->getJson('/api/modules/management-aset/v1/kondisi-aset?per_page=100&aktif=true')->assertOk()->assertJsonFragment(['id' => $baik]);
        $this->getJson('/api/modules/management-aset/v1/aset')->assertOk()->assertJsonFragment(['id' => $aset]);
        $this->getJson('/api/modules/management-aset/v1/reference-data/unit-kerja')->assertOk();

        $id = (string) $this->withHeader('Idempotency-Key', 'monitoring-'.Str::ulid())
            ->postJson(self::URL, [...$this->header($gudang), 'details' => [['aset_id' => $aset, 'ada' => true, 'kondisi_aset_id' => $baik]]])
            ->assertCreated()->json('data.id');
        $this->postJson(self::URL.'/'.$id.'/selesaikan', ['version' => 1])->assertOk()->assertJsonPath('data.details.0.hasil', 'sesuai');

        // Baca saja: master dan register tidak dapat ditulis.
        $this->withHeader('Idempotency-Key', 'lokasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/lokasi-aset', ['nama' => 'Lokasi liar'])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'kondisi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/kondisi-aset', ['nama' => 'Kondisi liar'])->assertForbidden();
        $this->patchJson('/api/modules/management-aset/v1/aset/'.$aset, ['nama' => 'Televisi diganti', 'version' => $asetVersion])->assertForbidden();
        $this->assertSame('Televisi', (string) DB::table('aset_tr_aset')->where('id', $aset)->value('nama'));
    }

    public function test_photo_evidence_attaches_to_the_document_and_its_lines(): void
    {
        Storage::fake('s3');
        $gudang = $this->location('Gudang foto');
        $aset = $this->receive('Genset', $gudang);
        $id = $this->draft($gudang, ['details' => [['aset_id' => $aset]]]);
        $attachments = '/api/v1/records/aset_tr_monitoring_aset/'.$id.'/attachments';

        $this->sebagaiPengguna($this->tenantId, ['management-aset.monitoring-aset.read', 'management-aset.monitoring-aset.update']);
        $this->upload($attachments, ['line_number' => 1])->assertCreated()->assertJsonPath('data.line_number', 1);
        $this->upload($attachments)->assertCreated();
        $this->upload($attachments, ['line_number' => 5])->assertStatus(422);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.monitoring-aset.read']);
        $this->getJson($attachments)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.can_change', false);
        $this->upload($attachments)->assertForbidden();
    }

    public function test_report_lists_completed_findings_from_the_frozen_snapshot(): void
    {
        $gudang = $this->location('Gudang laporan');
        $baik = $this->master('kondisi-aset', ['nama' => 'Baik']);
        $laptop = $this->receive('Laptop MSI', $gudang);
        $hilang = $this->receive('Kamera', $gudang);
        $selesai = $this->draft($gudang, ['details' => [
            ['aset_id' => $laptop, 'ada' => true, 'kondisi_aset_id' => $baik],
            ['aset_id' => $hilang, 'ada' => false, 'keterangan' => 'Tidak ditemukan'],
        ]]);
        $this->complete($selesai)->assertOk();
        // Draf tidak ikut laporan.
        $this->draft($gudang, ['details' => [['aset_id' => $laptop, 'ada' => false]]]);
        // Register berubah sesudahnya; laporan tetap menyebut keadaan saat diperiksa.
        DB::table('aset_tr_aset')->where('id', $hilang)->update(['lifecycle_state' => 'decommissioned']);

        $context = [
            'tenant_id' => $this->tenantId, 'legal_entity_id' => $this->legalEntityId, 'org_unit_id' => $this->unitId,
            'user_id' => 'pengguna-uji', 'permissions' => ['management-aset.monitoring-aset.read'],
            'data_policies' => ['management-aset.asset-responsibility' => ['all' => true]],
        ];
        $report = app(PenyediaLaporan::class)->dataset('laporan-monitoring-aset', $context, []);

        $this->assertCount(2, $report['tables']['baris']);
        $this->assertSame(2, $report['fields']['jumlah_aset']);
        $this->assertSame(1, $report['fields']['jumlah_tidak_sesuai']);
        $this->assertSame('2400000.00', $report['fields']['total_nilai_perolehan']);
        $rows = array_column($report['tables']['baris'], null, 'asset_kode');
        $row = $rows[(string) DB::table('aset_tr_aset')->where('id', $hilang)->value('kode')];
        $this->assertSame(
            ['Diterima', 'Tidak ada', 'Tidak sesuai', 'Tidak ditemukan', 'Gudang laporan', 'Gudang laporan'],
            [$row['kondisi_sistem'], $row['kondisi_fisik'], $row['status_monitoring'], $row['keterangan'], $row['lokasi'], $row['lokasi_tercatat']],
        );

        $filtered = app(PenyediaLaporan::class)->dataset('laporan-monitoring-aset', $context, ['kondisi_aset_id' => $baik]);
        $this->assertCount(1, $filtered['tables']['baris']);
        $this->assertSame('Baik', $filtered['fields']['filter_kondisi']);

        // Filter tambahan: dokumen menyaring semua barisnya, baris hanya meloloskan baris yang cocok.
        $kode = (string) DB::table('aset_tr_monitoring_aset')->where('id', $selesai)->value('kode');
        $additional = fn (array $filters): array => app(PenyediaLaporan::class)->dataset('laporan-monitoring-aset', $context, ['filters' => $filters]);
        $this->assertCount(2, $additional(['monitoring' => ['kode' => $kode]])['tables']['baris']);
        $this->assertCount(0, $additional(['monitoring' => ['kode' => '<>'.$kode]])['tables']['baris']);
        $mismatch = $additional(['monitoring' => ['kode' => $kode], 'baris' => ['hasil' => ['tidak_sesuai'], 'ada' => ['0']]]);
        $this->assertSame(['Tidak sesuai'], array_column($mismatch['tables']['baris'], 'status_monitoring'));
        $this->assertSame("Monitoring — No. bukti: {$kode} · Baris monitoring — Hasil: Tidak sesuai; Ada secara fisik: Tidak", $mismatch['fields']['filter_tambahan']);

        // Layar pratinjau membaca dataset yang sama, dengan izin baca monitoring.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.monitoring-aset.read'])
            ->getJson('/api/modules/management-aset/v1/laporan/laporan-monitoring-aset?dari=2026-08-01')
            ->assertOk()->assertJsonCount(2, 'data.tables.baris');
        $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.read'])
            ->getJson('/api/modules/management-aset/v1/laporan/laporan-monitoring-aset')
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function header(string $lokasiId, array $overrides = []): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId,
            'responsible_org_unit_id' => $this->unitId,
            'penanggung_jawab_user_id' => null,
            'lokasi_aset_id' => $lokasiId,
            'tanggal' => '2026-08-20',
            'keterangan' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function create(string $lokasiId, array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, self::ALL)
            ->withHeader('Idempotency-Key', $key ?? 'monitoring-'.Str::ulid())
            ->postJson(self::URL, $this->header($lokasiId, $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function draft(string $lokasiId, array $overrides = []): string
    {
        return (string) $this->create($lokasiId, $overrides)->assertCreated()->json('data.id');
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return TestResponse<Response>
     */
    private function save(string $id, string $lokasiId, array $details): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, self::ALL)
            ->patchJson(self::URL.'/'.$id, [...$this->header($lokasiId), 'details' => $details, 'version' => $this->version($id)]);
    }

    /** @return TestResponse<Response> */
    private function fill(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, self::ALL)
            ->postJson(self::URL.'/'.$id.'/isi-otomatis', ['version' => $this->version($id)]);
    }

    /** @return TestResponse<Response> */
    private function complete(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.monitoring-aset.read', 'management-aset.monitoring-aset.complete'])
            ->postJson(self::URL.'/'.$id.'/selesaikan', ['version' => $this->version($id)]);
    }

    /** @return TestResponse<Response> */
    private function show(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.monitoring-aset.read'])->getJson(self::URL.'/'.$id);
    }

    /** @return array<string, array<string, mixed>> */
    private function linesByAsset(string $id): array
    {
        $lines = $this->show($id)->assertOk()->json('data.details');
        $this->assertIsArray($lines);

        return array_column($lines, null, 'aset_id');
    }

    private function version(string $id): int
    {
        return (int) DB::table('aset_tr_monitoring_aset')->where('id', $id)->value('version');
    }

    /** @return array<string, array<string, mixed>> */
    private function registerRows(): array
    {
        return DB::table('aset_tr_aset')->orderBy('id')->get()->keyBy('id')->map(static fn ($row): array => (array) $row)->all();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function receive(string $nama, string $lokasiId, array $extra = []): string
    {
        if ($this->groupId === '') {
            $this->groupId = $this->master('group-aset', ['nama' => 'Group monitoring']);
            $this->jenisId = $this->master('jenis-aset', ['nama' => 'Jenis monitoring']);
        }

        return $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $this->legalEntityId,
            'nama' => $nama,
            'group_aset_id' => $this->groupId,
            'jenis_aset_id' => $this->jenisId,
            'acquired_on' => '2026-06-01',
            'placed_in_service_on' => '2026-06-15',
            'acquisition_value' => 1_200_000,
            'currency_code' => 'IDR',
            'usage_org_unit_id' => $this->unitId,
            'lokasi_aset_id' => $lokasiId,
            ...$extra,
        ]);
    }

    private function location(string $nama): string
    {
        return $this->master('lokasi-aset', ['nama' => $nama]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function master(string $resource, array $payload): string
    {
        return (string) $this->sebagaiPengguna($this->tenantId, array_map(
            static fn (string $action): string => 'management-aset.'.$resource.'.'.$action,
            ['read', 'create', 'update', 'archive'],
        ))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, $payload)
            ->assertCreated()->json('data.id');
    }

    /**
     * Anggota tenant yang memegang duty katalog sungguhan hasil `app:register-manifest`, dengan lingkup
     * seluruh organisasi.
     *
     * @param  list<string>  $duties
     */
    private function withDuty(array $duties): void
    {
        $user = User::factory()->create();
        $membership = TenantMembership::create(['tenant_id' => $this->tenantId, 'user_id' => $user->id, 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->tenantId, 'name' => 'Role '.Str::random(6), 'is_active' => true]);
        $role->duties()->sync($duties);
        $assignment = $membership->roleAssignments()->create(['role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()->subMinute()]);
        $this->beriLingkupKebijakan($this->tenantId, (string) $assignment->id, ['policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => null, 'organization_id' => null]);
        $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function upload(string $url, array $extra = []): TestResponse
    {
        return $this->post($url, ['file' => UploadedFile::fake()->createWithContent('Foto bukti.pdf', self::PDF), ...$extra], ['Accept' => 'application/json']);
    }
}
