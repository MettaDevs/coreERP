<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Penerimaan aset sebagai dokumen: satu kedatangan, banyak aset.
 *
 * Kasus yang melahirkannya datang dari lapangan: dua puluh kursi seharga lima ratus ribu
 * datang dengan satu surat jalan, harus dikapitalisasi dan disusutkan tiap bulan, dan tiap
 * kursi tetap punya kodenya sendiri karena kode itulah yang tertempel di barangnya.
 *
 * Yang dijaga di sini adalah hal-hal yang tidak boleh regresi diam-diam: draf belum
 * melahirkan aset apa pun, nilai dibandingkan per unit dan bukan per dokumen, penyelesaian
 * yang diulang tidak menggandakan aset, dan menyusun berkas bukan izin yang sama dengan
 * menambah aset ke register.
 */
class PenerimaanAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        // Jurnal perolehan terbit saat penerimaan diselesaikan, jadi entitas legalnya harus ada di
        // Core, dan pembelian pada mode bawaan `direct_payable` membawa vendor (TODO 9.2.1).
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        Http::preventStrayRequests();
    }

    public function test_draf_belum_melahirkan_aset_apa_pun(): void
    {
        $dokumen = $this->draf([$this->baris(jumlah: 20)]);

        $this->assertSame('draft', (string) DB::table('aset_tr_penerimaan_aset')->where('id', $dokumen)->value('status'));
        $this->assertSame(0, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $dokumen)->count());
    }

    /**
     * Kasus QA apa adanya: dua puluh kursi, satu dokumen, dua puluh aset bernomor sendiri
     * yang masing-masing menyusut.
     */
    public function test_satu_baris_berjumlah_dua_puluh_melahirkan_dua_puluh_aset_bernomor_sendiri(): void
    {
        $group = $this->groupSiapSusut();
        $dokumen = $this->draf([$this->baris(jumlah: 20, nilai: 500_000, group: $group, nama: 'Kursi tunggu')]);

        $this->selesaikan($dokumen)->assertOk()->assertJsonPath('data.status', 'selesai');

        $aset = DB::table('aset_tr_aset')->where('penerimaan_aset_id', $dokumen)->orderBy('kode')->get();
        $this->assertCount(20, $aset);
        // Kodenya berbeda satu sama lain, dan tidak satu pun diturunkan dari nomor
        // dokumen: kode aset adalah kunci alami yang tidak boleh bergantung pada dokumen
        // yang masih dapat dikoreksi.
        $this->assertCount(20, $aset->pluck('kode')->unique());
        foreach ($aset as $baris) {
            $this->assertSame('500000.00', (string) $baris->acquisition_value);
            $this->assertSame(1, DB::table('aset_tr_penempatan_aset')->where('aset_id', $baris->id)->count());
            $this->assertDatabaseHas('aset_tr_buku_aset', ['aset_id' => $baris->id, 'depreciate' => true]);
        }
    }

    /**
     * Ambang kapitalisasi dibandingkan per unit, bukan per dokumen.
     *
     * Ini jebakan yang paling mudah terpasang salah: dua puluh kali lima ratus ribu adalah
     * sepuluh juta, dan penjumlahan itu akan membuat seluruh kursi menyusut padahal
     * satuannya jauh di bawah ambang.
     */
    public function test_ambang_kapitalisasi_dibandingkan_per_unit_bukan_per_dokumen(): void
    {
        $group = $this->groupSiapSusut();
        DB::table('aset_m_group_aset')->where(['tenant_id' => $this->tenantId, 'id' => $group])
            ->update(['capitalization_threshold' => 1_000_000]);

        $dokumen = $this->draf([$this->baris(jumlah: 20, nilai: 500_000, group: $group)]);
        // Layar diberi tahu sebelum nomornya terbit, bukan sesudah.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read'])
            ->getJson($this->alamat($dokumen).'/ringkasan')
            ->assertOk()
            ->assertJsonPath('data.jumlah_aset', 20)
            ->assertJsonPath('data.peringatan.0.line_number', 1);

        $this->selesaikan($dokumen)->assertOk();

        $aset = DB::table('aset_tr_aset')->where('penerimaan_aset_id', $dokumen)->pluck('id');
        $this->assertCount(20, $aset);
        $this->assertSame(0, DB::table('aset_tr_buku_aset')->whereIn('aset_id', $aset)->where('depreciate', true)->count());
    }

    public function test_penyelesaian_yang_diulang_tidak_menggandakan_aset(): void
    {
        $dokumen = $this->draf([$this->baris(jumlah: 3)]);
        $this->selesaikan($dokumen)->assertOk();

        // Percobaan kedua ditolak karena statusnya sudah selesai; yang dijaga di sini
        // adalah tidak adanya tiga aset tambahan sebagai efek sampingnya.
        $this->selesaikan($dokumen, version: 2)->assertStatus(422);

        $this->assertSame(3, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $dokumen)->count());
    }

    public function test_dokumen_selesai_tidak_dapat_diubah_atau_diarsipkan(): void
    {
        $dokumen = $this->draf([$this->baris(jumlah: 2)]);
        $this->selesaikan($dokumen)->assertOk();

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.penerimaan-aset.update'])
            ->patchJson($this->alamat($dokumen), ['version' => 2, ...$this->payload([$this->baris(jumlah: 2)])])
            ->assertStatus(422);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.penerimaan-aset.archive'])
            ->deleteJson($this->alamat($dokumen), ['version' => 2])
            ->assertStatus(422);
    }

    /**
     * Menyusun berkasnya dan benar-benar menambah aset ke register adalah dua wewenang
     * berbeda — pemisahan yang sama yang sudah dipakai mutasi.
     */
    public function test_menyusun_dokumen_bukan_izin_yang_sama_dengan_menambah_aset(): void
    {
        $dokumen = $this->draf([$this->baris(jumlah: 1)]);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.penerimaan-aset.update'])
            ->postJson($this->alamat($dokumen).'/selesaikan', ['version' => 1])
            ->assertForbidden();
    }

    public function test_penerimaan_melebihi_permintaan_pembelian_ditolak_dengan_sisanya(): void
    {
        $permintaan = $this->barisPermintaan(quantity: 10);
        $group = $this->groupSiapSusut();

        $pertama = $this->draf([$this->baris(jumlah: 6, group: $group, permintaan: $permintaan)]);
        $this->selesaikan($pertama)->assertOk();

        $kedua = $this->draf([$this->baris(jumlah: 6, group: $group, permintaan: $permintaan)]);
        $this->selesaikan($kedua)
            ->assertStatus(422)
            ->assertJsonFragment(['Baris "Kursi tunggu" melebihi permintaan pembelian. Sisa yang belum diterima tinggal 4.']);

        // Sisa persis pun diterima: batasnya "lebih dari", bukan "sama dengan".
        $ketiga = $this->draf([$this->baris(jumlah: 4, group: $group, permintaan: $permintaan)]);
        $this->selesaikan($ketiga)->assertOk();
    }

    /**
     * Nomor seri sengaja kosong saat penerimaan — kardusnya belum dibuka — dan diisi
     * sesudahnya lewat daftar yang menyempit ke dokumen ini saja.
     */
    public function test_aset_terbit_dapat_dibaca_untuk_pengisian_nomor_seri(): void
    {
        $dokumen = $this->draf([$this->baris(jumlah: 4)]);
        $this->selesaikan($dokumen)->assertOk();

        $daftar = $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read'])
            ->getJson($this->alamat($dokumen).'/aset')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->json('data');

        $this->assertNull($daftar[0]['serial_number']);
    }

    /**
     * Sejak 18 September 2026 dokumen penerimaan adalah satu-satunya pintu ke register.
     *
     * Selama `POST /aset` masih hidup, kalimat "inventarisasi aset adalah penerimaan"
     * tidak benar: aset masih bisa lahir tanpa dokumen, tanpa rujukan permintaan
     * pembelian, dan tanpa peringatan ambang kapitalisasi.
     */
    public function test_aset_tidak_dapat_dibuat_langsung_tanpa_dokumen_penerimaan(): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.create'])
            ->withHeader('Idempotency-Key', 'langsung-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/aset', [
                'legal_entity_id' => $this->legalEntityId,
                'nama' => 'Aset tanpa dokumen',
                'group_aset_id' => $this->groupSiapSusut(),
                'jenis_aset_id' => $this->jenis(),
                'acquired_on' => '2026-07-01',
                'acquisition_value' => 1_000_000,
                'currency_code' => 'IDR',
                'usage_org_unit_id' => $this->orgUnitId,
            ])
            ->assertStatus(405);
    }

    public function test_nomor_seri_diisi_sekaligus_lewat_grid_dokumen(): void
    {
        $dokumen = $this->draf([$this->baris(jumlah: 3)]);
        $this->selesaikan($dokumen)->assertOk();
        $aset = DB::table('aset_tr_aset')->where('penerimaan_aset_id', $dokumen)->orderBy('kode')->pluck('id')->all();

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.aset.update'])
            ->putJson($this->alamat($dokumen).'/aset', ['serial' => [
                ['aset_id' => $aset[0], 'serial_number' => 'SN-001'],
                ['aset_id' => $aset[1], 'serial_number' => 'SN-002'],
                // Dikosongkan dengan sengaja: stikernya belum ketemu.
                ['aset_id' => $aset[2], 'serial_number' => ''],
            ]])
            ->assertOk()
            ->assertJsonPath('data.0.serial_number', 'SN-001');

        $this->assertDatabaseHas('aset_tr_aset', ['id' => $aset[1], 'serial_number' => 'SN-002']);
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $aset[2], 'serial_number' => null]);
    }

    /** Grid satu dokumen tidak boleh menyentuh aset dari dokumen lain. */
    public function test_nomor_seri_menolak_aset_dari_dokumen_lain(): void
    {
        $pertama = $this->draf([$this->baris(jumlah: 1)]);
        $kedua = $this->draf([$this->baris(jumlah: 1)]);
        $this->selesaikan($pertama)->assertOk();
        $this->selesaikan($kedua)->assertOk();
        $asing = (string) DB::table('aset_tr_aset')->where('penerimaan_aset_id', $kedua)->value('id');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.aset.update'])
            ->putJson($this->alamat($pertama).'/aset', ['serial' => [
                ['aset_id' => $asing, 'serial_number' => 'SN-NAKAL'],
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('serial');

        $this->assertDatabaseHas('aset_tr_aset', ['id' => $asing, 'serial_number' => null]);
    }

    public function test_jumlah_wajib_setidaknya_satu(): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.create'])
            ->withHeader('Idempotency-Key', 'pnr-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/penerimaan-aset', $this->payload([$this->baris(jumlah: 0)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.jumlah']);
    }

    /** @param list<array<string, mixed>> $details */
    private function draf(array $details): string
    {
        return (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.create', 'management-aset.penerimaan-aset.read'])
            ->withHeader('Idempotency-Key', 'pnr-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/penerimaan-aset', $this->payload($details))
            ->assertCreated()
            ->json('data.id');
    }

    /** @return TestResponse<Response> */
    private function selesaikan(string $id, int $version = 1): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.aset.create'])
            ->postJson($this->alamat($id).'/selesaikan', ['version' => $version]);
    }

    private function alamat(string $id): string
    {
        return '/api/modules/management-aset/v1/penerimaan-aset/'.$id;
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    private function payload(array $details): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId,
            'responsible_org_unit_id' => $this->orgUnitId,
            'tanggal' => '2026-07-01',
            'tanggal_siap_pakai' => '2026-07-01',
            'currency_code' => 'IDR',
            'vendor_id' => $this->pastikanVendorUji($this->tenantId, $this->legalEntityId),
            'details' => $details,
        ];
    }

    /** @return array<string, mixed> */
    private function baris(int $jumlah, int $nilai = 500_000, ?string $group = null, ?string $permintaan = null, string $nama = 'Kursi tunggu'): array
    {
        return [
            'nama' => $nama,
            'group_aset_id' => $group ?? $this->groupSiapSusut(),
            'jenis_aset_id' => $this->jenis(),
            'jumlah' => $jumlah,
            'nilai_per_unit' => $nilai,
            'permintaan_pembelian_detail_id' => $permintaan,
        ];
    }

    /** Group dengan satu buku yang benar-benar menghitung, supaya asetnya menyusut. */
    private function groupSiapSusut(): string
    {
        $now = now();
        $group = (string) Str::ulid();
        $profil = (string) Str::ulid();
        $buku = (string) Str::ulid();
        DB::table('aset_m_group_aset')->insert([
            'id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.Str::ulid(),
            'kode' => 'G'.Str::random(8), 'nama' => 'Perabot kantor', 'aktif' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_profil_penyusutan')->insert([
            'id' => $profil, 'tenant_id' => $this->tenantId, 'creation_key' => 'profil-'.Str::ulid(),
            'kode' => 'P'.Str::random(8), 'nama' => 'Garis lurus 48 bulan', 'aktif' => true,
            'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar',
            'useful_life_periods' => 48, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $buku, 'tenant_id' => $this->tenantId, 'creation_key' => 'buku-'.Str::ulid(),
            'kode' => 'B'.Str::random(8), 'nama' => 'Buku komersial', 'aktif' => true,
            'posting_layer' => 'current',
            'depreciation_profile_id' => $profil, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $group,
            'buku_id' => $buku, 'depreciate' => true, 'useful_life_periods' => 48,
            'convention' => 'full_month', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $group;
    }

    private function jenis(): string
    {
        $now = now();
        $id = (string) Str::ulid();
        DB::table('aset_m_jenis_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'jenis-'.Str::ulid(),
            'kode' => 'J'.Str::random(8), 'nama' => 'Perabot', 'aktif' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** Satu baris permintaan pembelian yang sudah disetujui, sebagai rujukan penerimaan. */
    private function barisPermintaan(int $quantity): string
    {
        $now = now();
        $header = (string) Str::ulid();
        $baris = (string) Str::ulid();
        $satuan = (string) Str::ulid();
        DB::table('aset_tr_permintaan_pengadaan_aset')->insert([
            'id' => $header, 'tenant_id' => $this->tenantId, 'creation_key' => 'pp-'.Str::ulid(),
            'kode' => 'PP'.Str::random(8), 'legal_entity_id' => $this->legalEntityId,
            'requesting_org_unit_id' => $this->orgUnitId, 'requester_user_id' => (string) Str::ulid(),
            'requested_on' => '2026-06-01', 'status' => 'draft', 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_tr_permintaan_pengadaan_aset_details')->insert([
            'id' => $baris, 'tenant_id' => $this->tenantId, 'request_id' => $header,
            'line_number' => 1, 'jenis_aset_id' => $this->jenis(), 'satuan_id' => $satuan,
            'nama_aset' => 'Kursi tunggu', 'quantity' => $quantity, 'specification' => 'Kursi tunggu tiga dudukan',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $baris;
    }
}
