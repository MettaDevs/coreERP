<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Models\TenantMembership;
use App\Models\User;
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
 * Mutasi aset sebagai dokumen: berita acara serah terima yang memuat beberapa aset,
 * disiapkan sebagai draf, lalu diselesaikan.
 *
 * Yang dijaga di sini adalah hal-hal yang tidak boleh regresi diam-diam: draf tidak
 * memindahkan apa pun, keadaan asal dibekukan pada saat penyelesaian dan bukan saat
 * pengetikan, dokumen selesai tidak dapat disunting, dan menyusun dokumen bukan izin yang
 * sama dengan memindahkan aset.
 */
class MutasiAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    private string $unitTujuanId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        $this->unitTujuanId = (string) Str::ulid();
        Http::preventStrayRequests();
    }

    public function test_draf_tidak_memindahkan_aset_apa_pun(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang pusat']);
        $sebelum = DB::table('aset_tr_aset')->where('id', $aset)->first();

        $mutasi = $this->draft([$aset], $tujuan);

        $this->assertSame('draft', (string) DB::table('aset_tr_mutasi_aset')->where('id', $mutasi)->value('status'));
        $sesudah = DB::table('aset_tr_aset')->where('id', $aset)->first();
        $this->assertSame($sebelum->lokasi_aset_id, $sesudah->lokasi_aset_id);
        $this->assertSame($sebelum->responsible_org_unit_id, $sesudah->responsible_org_unit_id);
        // Penempatan yang ada hanyalah yang lahir dari penerimaan aset.
        $this->assertSame(1, DB::table('aset_tr_penempatan_aset')->where('aset_id', $aset)->count());
    }

    public function test_penyelesaian_memindahkan_seluruh_aset_pada_dokumen(): void
    {
        $pertama = $this->receive(['nama' => 'Laptop']);
        $kedua = $this->receive(['nama' => 'Monitor']);
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Ruang Implementor']);
        $mutasi = $this->draft([$pertama, $kedua], $tujuan);

        $this->selesaikan($mutasi)->assertOk()->assertJsonPath('data.status', 'selesai');

        foreach ([$pertama, $kedua] as $aset) {
            $this->assertDatabaseHas('aset_tr_aset', [
                'id' => $aset,
                'lokasi_aset_id' => $tujuan,
                'responsible_org_unit_id' => $this->unitTujuanId,
            ]);
            // Satu berita acara menghasilkan satu baris riwayat per aset, dan baris itu
            // menyebut dokumennya — tanpa itu riwayat tahu aset berpindah tetapi tidak
            // dengan bukti mana.
            $this->assertDatabaseHas('aset_tr_penempatan_aset', [
                'aset_id' => $aset,
                'mutasi_aset_id' => $mutasi,
                'lokasi_aset_id' => $tujuan,
                'usage_org_unit_id' => $this->unitTujuanId,
            ]);
        }
    }

    public function test_keadaan_asal_dibekukan_saat_diselesaikan_bukan_saat_diketik(): void
    {
        $asal = $this->master('lokasi-aset', ['nama' => 'Gudang lama']);
        $antara = $this->master('lokasi-aset', ['nama' => 'Gudang transit']);
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang baru']);
        $aset = $this->receive();
        DB::table('aset_tr_aset')->where('id', $aset)->update(['lokasi_aset_id' => $asal]);

        $mutasi = $this->draft([$aset], $tujuan);

        // Aset berpindah sesudah draf diketik. Berita acara harus menyebut tempat aset
        // berada saat serah terima benar-benar terjadi, bukan saat dokumennya disusun.
        DB::table('aset_tr_aset')->where('id', $aset)->update(['lokasi_aset_id' => $antara]);
        $this->selesaikan($mutasi)->assertOk();

        $this->assertDatabaseHas('aset_tr_mutasi_aset_details', [
            'mutasi_aset_id' => $mutasi,
            'aset_id' => $aset,
            'asal_lokasi_id' => $antara,
        ]);
    }

    public function test_draf_membaca_asal_dari_keadaan_aset_sekarang(): void
    {
        $asal = $this->master('lokasi-aset', ['nama' => 'Ruang server']);
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Ruang rapat']);
        $aset = $this->receive();
        DB::table('aset_tr_aset')->where('id', $aset)->update(['lokasi_aset_id' => $asal]);

        $mutasi = $this->draft([$aset], $tujuan);

        // Selama draf, `asal_lokasi_id` masih kosong di database tetapi jawabannya tetap
        // menyebut lokasi asal — itulah yang membuat layar tidak perlu memintanya diketik.
        $this->show($mutasi)
            ->assertOk()
            ->assertJsonPath('data.details.0.asal_lokasi_id', null)
            ->assertJsonPath('data.details.0.asal_lokasi_efektif_id', $asal)
            ->assertJsonPath('data.details.0.asal_lokasi_nama', 'Ruang server');
    }

    public function test_dokumen_selesai_tidak_dapat_diubah_atau_diarsipkan(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang arsip']);
        $mutasi = $this->draft([$aset], $tujuan);
        $this->selesaikan($mutasi)->assertOk();
        $version = (int) DB::table('aset_tr_mutasi_aset')->where('id', $mutasi)->value('version');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.update'])
            ->patchJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasi, [
                ...$this->payload([$aset], $tujuan),
                'version' => $version,
            ])->assertStatus(422);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasi, ['version' => $version])
            ->assertStatus(422);
    }

    public function test_menyusun_dokumen_bukan_izin_untuk_memindahkan_aset(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang B']);
        $mutasi = $this->draft([$aset], $tujuan);
        $version = (int) DB::table('aset_tr_mutasi_aset')->where('id', $mutasi)->value('version');

        // Seluruh izin dokumen dipegang, tetapi bukan `aset.mutate`.
        $this->sebagaiPengguna($this->tenantId, [
            'management-aset.mutasi-aset.read',
            'management-aset.mutasi-aset.create',
            'management-aset.mutasi-aset.update',
            'management-aset.mutasi-aset.archive',
        ])->postJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasi.'/selesaikan', ['version' => $version])
            ->assertForbidden();

        $this->assertSame('draft', (string) DB::table('aset_tr_mutasi_aset')->where('id', $mutasi)->value('status'));
    }

    public function test_aset_yang_sudah_dilepas_tidak_dapat_dimasukkan(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang C']);
        DB::table('aset_tr_aset')->where('id', $aset)->update(['lifecycle_state' => 'disposed']);

        $this->draftResponse([$aset], $tujuan)->assertStatus(422);
    }

    public function test_aset_yang_dilepas_setelah_draf_menahan_penyelesaian(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang D']);
        $mutasi = $this->draft([$aset], $tujuan);

        // Dekomisioning terjadi di antara penyusunan draf dan serah terimanya. Pemeriksaan
        // saat membuat draf tidak dapat melihat ini; pemeriksaan kedua yang menahannya.
        DB::table('aset_tr_aset')->where('id', $aset)->update(['lifecycle_state' => 'disposed']);

        $this->selesaikan($mutasi)->assertStatus(422);
        $this->assertSame('draft', (string) DB::table('aset_tr_mutasi_aset')->where('id', $mutasi)->value('status'));
    }

    public function test_details_berupa_objek_json_tetap_dinomori_berurut(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang H']);

        // `['required','array']` meloloskan objek JSON berkunci teks. Tanpa normalisasi,
        // kunci itu terbawa ke penomoran baris dan berhenti sebagai 500, bukan sebagai
        // dokumen yang tersimpan benar.
        $mutasi = (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', 'mutasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', [
                ...$this->payload([$aset], $tujuan),
                'details' => ['baris-pertama' => ['aset_id' => $aset]],
            ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('aset_tr_mutasi_aset_details', [
            'mutasi_aset_id' => $mutasi,
            'aset_id' => $aset,
            'line_number' => 1,
        ]);
    }

    public function test_unit_kerja_dan_orang_dipulangkan_sebagai_nama(): void
    {
        $unitId = $this->unitKerjaCore('Divisi Implementor');
        $penyerah = $this->penggunaCore('Eva Yanti');
        $penerima = $this->penggunaCore('Putu Diva Cipta');
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Ruang Dev']);

        $mutasi = (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', 'mutasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', [
                ...$this->payload([$aset], $tujuan),
                'tujuan_org_unit_id' => $unitId,
                'diserahkan_oleh_user_id' => $penyerah,
                'diterima_oleh_user_id' => $penerima,
            ])->assertCreated()->json('data.id');

        // Id tetap dipulangkan karena itu yang dikirim balik saat menyimpan; nama
        // ditambahkan di sebelahnya supaya layar tidak pernah perlu menampilkan ULID.
        $this->show($mutasi)
            ->assertOk()
            ->assertJsonPath('data.tujuan_org_unit_id', $unitId)
            ->assertJsonPath('data.tujuan_org_unit_nama', 'Divisi Implementor')
            ->assertJsonPath('data.diserahkan_oleh_nama', 'Eva Yanti')
            ->assertJsonPath('data.diterima_oleh_nama', 'Putu Diva Cipta');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.read'])
            ->getJson('/api/modules/management-aset/v1/mutasi-aset')
            ->assertOk()
            ->assertJsonPath('data.0.tujuan_org_unit_nama', 'Divisi Implementor');
    }

    public function test_referensi_unit_kerja_dan_anggota_memulangkan_nama(): void
    {
        $this->unitKerjaCore('Divisi Engineering');
        $this->penggunaCore('Eva Yanti');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.read'])
            ->getJson('/api/modules/management-aset/v1/reference-data/unit-kerja')
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'Divisi Engineering');

        $anggota = $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.read'])
            ->getJson('/api/modules/management-aset/v1/reference-data/anggota')
            ->assertOk()->json('data');
        $this->assertContains('Eva Yanti', array_column($anggota, 'nama'));

        // Tanpa satu pun izin layar yang memakainya, daftar ini tertutup.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.read'])
            ->getJson('/api/modules/management-aset/v1/reference-data/unit-kerja')
            ->assertForbidden();
    }

    public function test_aset_dari_badan_hukum_lain_ditolak(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang lintas entitas']);

        // Nomor berita acara terbit per badan hukum. Memuat aset milik badan hukum lain
        // membuat buktinya bernomor atas nama pihak yang tidak memilikinya.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', 'mutasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', [
                ...$this->payload([$aset], $tujuan),
                'legal_entity_id' => (string) Str::ulid(),
            ])->assertStatus(422);

        $this->assertSame(0, DB::table('aset_tr_mutasi_aset')->count());
    }

    public function test_satu_aset_tidak_boleh_muncul_dua_kali(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang E']);

        $this->draftResponse([$aset, $aset], $tujuan)->assertStatus(422);
    }

    public function test_lifecycle_state_tidak_disentuh_oleh_mutasi(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang penyimpanan']);
        $sebelum = (string) DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state');

        $this->selesaikan($this->draft([$aset], $tujuan))->assertOk();

        // Di Dynamics 365, memasang aset pada functional location dan mengubah lifecycle
        // state adalah dua tindakan terpisah. Menggabungkannya membuat aset yang dimutasi
        // ke gudang ikut berstatus dipakai.
        $this->assertSame($sebelum, (string) DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));
    }

    public function test_dimensi_keuangan_mengikuti_unit_yang_dipetakan_pada_lokasi_tujuan(): void
    {
        $unitLokasi = (string) Str::ulid();
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Pabrik 1', 'org_unit_id' => $unitLokasi]);

        $this->selesaikan($this->draft([$aset], $tujuan))->assertOk();

        // Padanan toggle "Update asset dimension" pada functional location type di F&O:
        // pembebanan mengikuti lokasi bila lokasinya dipetakan, bukan unit pada dokumen.
        $this->assertDatabaseHas('aset_tr_aset', [
            'id' => $aset,
            'financial_dimension_org_unit_id' => $unitLokasi,
            'responsible_org_unit_id' => $this->unitTujuanId,
        ]);
    }

    public function test_nomor_diterbitkan_core_dan_permintaan_ulang_tidak_menerbitkan_nomor_kedua(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang F']);
        $key = 'mutasi-'.Str::ulid();

        $pertama = $this->draftResponse([$aset], $tujuan, $key)->assertCreated();
        $kedua = $this->draftResponse([$aset], $tujuan, $key)->assertOk();

        $this->assertSame($pertama->json('data.kode'), $kedua->json('data.kode'));
        $kedua->assertHeader('Idempotent-Replayed', 'true');
        $this->assertStringStartsWith('MUTA', (string) $pertama->json('data.kode'));
        $this->assertSame(1, DB::table('aset_tr_mutasi_aset')->count());
    }

    public function test_versi_usang_ditolak_saat_menyelesaikan(): void
    {
        $aset = $this->receive();
        $tujuan = $this->master('lokasi-aset', ['nama' => 'Gudang G']);
        $mutasi = $this->draft([$aset], $tujuan);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate', 'management-aset.mutasi-aset.read'])
            ->postJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasi.'/selesaikan', ['version' => 99])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version');
    }

    /** Unit operasi milik Core, supaya direktori punya sesuatu untuk diterjemahkan. */
    private function unitKerjaCore(string $nama): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'name' => $nama,
            'classification' => 'operating_unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Anggota tenant; yang dipulangkan id **pengguna**, bukan id keanggotaan. */
    private function penggunaCore(string $nama): string
    {
        $pengguna = User::factory()->create(['name' => $nama]);
        TenantMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $pengguna->id,
            'system_role' => 'user',
            'status' => 'active',
        ]);

        return (string) $pengguna->id;
    }

    /**
     * @param  list<string>  $asetIds
     */
    private function draft(array $asetIds, string $tujuanLokasiId): string
    {
        return (string) $this->draftResponse($asetIds, $tujuanLokasiId)->assertCreated()->json('data.id');
    }

    /**
     * @param  list<string>  $asetIds
     * @return TestResponse<Response>
     */
    private function draftResponse(array $asetIds, string $tujuanLokasiId, ?string $key = null): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', $key ?? 'mutasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', $this->payload($asetIds, $tujuanLokasiId));
    }

    /**
     * @param  list<string>  $asetIds
     * @return array<string, mixed>
     */
    private function payload(array $asetIds, string $tujuanLokasiId): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId,
            'responsible_org_unit_id' => $this->orgUnitId,
            'tanggal' => '2026-09-17',
            'tujuan_lokasi_id' => $tujuanLokasiId,
            'tujuan_org_unit_id' => $this->unitTujuanId,
            'diserahkan_oleh_user_id' => 'user-penyerah',
            'diterima_oleh_user_id' => 'user-penerima',
            'alasan' => 'Pindah penugasan',
            'details' => array_map(static fn (string $id): array => ['aset_id' => $id], $asetIds),
        ];
    }

    /** @return TestResponse<Response> */
    private function selesaikan(string $mutasiId): TestResponse
    {
        $version = (int) DB::table('aset_tr_mutasi_aset')->where('id', $mutasiId)->value('version');

        return $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate', 'management-aset.mutasi-aset.read'])
            ->postJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasiId.'/selesaikan', ['version' => $version]);
    }

    /** @return TestResponse<Response> */
    private function show(string $mutasiId): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.read'])
            ->getJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasiId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function receive(array $overrides = []): string
    {
        $group = $this->master('group-aset', ['nama' => 'Group '.Str::random(6)]);
        $jenis = $this->master('jenis-aset', ['nama' => 'Jenis '.Str::random(6)]);

        return $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $this->legalEntityId,
            'nama' => $overrides['nama'] ?? 'Aset mutasi uji',
            'group_aset_id' => $group,
            'jenis_aset_id' => $jenis,
            'acquired_on' => '2026-06-01',
            'placed_in_service_on' => '2026-06-15',
            'acquisition_value' => 1_200_000,
            'currency_code' => 'IDR',
            'usage_org_unit_id' => $this->orgUnitId,
        ]);
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
}
