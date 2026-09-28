<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Tests\TestCase;

class RegisterAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private string $tenantId;

    /** Badan hukum aset uji; dokumen mutasi harus menyebut yang sama. */
    private string $legalEntityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();

        // Tidak ada lagi `Http::fake` di sini. Nomor, kalender fiskal, dan satuan sudah lewat
        // kontrak Core sejak F3-06 sampai F3-08, dan workflow menyusul pada F3-09; sebuah
        // pemalsuan yang tidak dipakai siapa pun hanya menyembunyikan permintaan yang tersisa.
    }

    public function test_direct_receipt_keeps_receiver_usage_unit_and_custodian_separate(): void
    {
        $classification = $this->classification();
        $legalEntity = (string) Str::ulid();
        $receivingUnit = (string) Str::ulid();
        $usageUnit = (string) Str::ulid();
        $receiver = (string) Str::ulid();
        $custodian = (string) Str::ulid();

        $asetId = $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $legalEntity, 'nama' => 'Aset uji penerimaan', ...$classification,
            'acquired_on' => '2026-07-28', 'acquisition_value' => 12000000,
            'currency_code' => 'IDR', 'receiving_org_unit_id' => $receivingUnit,
            'usage_org_unit_id' => $usageUnit, 'received_by_user_id' => $receiver,
            'custodian_user_id' => $custodian,
        ]);

        // Kode aset tetap dari number sequence `management-aset.aset`, bukan diturunkan
        // dari nomor dokumennya: ia kunci alami yang dipakai seumur hidup aset.
        $this->assertDatabaseHas('aset_tr_aset', [
            'id' => $asetId, 'kode' => $this->awalanNomor('management-aset.aset').'-000001',
        ]);
        $this->assertDatabaseHas('aset_tr_penempatan_aset', [
            'tenant_id' => $this->tenantId, 'aset_id' => $asetId,
            'receiving_org_unit_id' => $receivingUnit, 'usage_org_unit_id' => $usageUnit,
            'received_by_user_id' => $receiver, 'custodian_user_id' => $custodian,
        ]);
        // Dua nomor, bukan satu: satu untuk dokumen penerimaannya, satu untuk asetnya.
        $this->assertSame(2, $this->jumlahNomorTerbit(), 'Penerbitan nomor tidak terjadi.');
    }

    public function test_mutation_adds_history_instead_of_rewriting_receipt(): void
    {
        $asetId = $this->receive();
        $newUnit = (string) Str::ulid();

        // Lewat dokumen mutasi sejak `POST /aset/{id}/penempatan` dipensiunkan. Yang dijaga
        // tetap sama: penerimaan tidak ditulis ulang, riwayatnya bertambah satu baris.
        $mutasi = (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', 'mutasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $newUnit,
                'tanggal' => '2026-08-01',
                'tujuan_org_unit_id' => $newUnit,
                'alasan' => 'Pindah pengguna',
                'details' => [['aset_id' => $asetId]],
            ])->assertCreated()->json('data.id');
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate', 'management-aset.mutasi-aset.read'])
            ->postJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasi.'/selesaikan', ['version' => 1])
            ->assertOk();

        $this->assertDatabaseCount('aset_tr_penempatan_aset', 2);
        $this->assertDatabaseHas('aset_tr_penempatan_aset', ['aset_id' => $asetId, 'usage_org_unit_id' => $newUnit, 'effective_on' => '2026-08-01']);
    }

    public function test_register_hides_aset_outside_the_signed_operating_unit_scope(): void
    {
        $firstLegalEntity = (string) Str::ulid();
        $secondLegalEntity = (string) Str::ulid();
        $firstUnit = (string) Str::ulid();
        $secondUnit = (string) Str::ulid();
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();
        $crossFirst = (string) Str::ulid();
        $crossSecond = (string) Str::ulid();
        $now = now();
        // Klasifikasi tidak diuji di sini; satu pasang dipakai bersama agar yang tersaring
        // benar-benar berasal dari legal entity dan operating unit.
        $scopeClassification = $this->classification();
        DB::table('aset_tr_aset')->insert([
            ['id' => $first, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-a', 'kode' => 'AST-SCOPE-A', 'nama' => 'Aset scope A', 'legal_entity_id' => $firstLegalEntity, 'responsible_org_unit_id' => $firstUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $second, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-b', 'kode' => 'AST-SCOPE-B', 'nama' => 'Aset scope B', 'legal_entity_id' => $secondLegalEntity, 'responsible_org_unit_id' => $secondUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $crossFirst, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-c', 'kode' => 'AST-SCOPE-C', 'nama' => 'Aset scope C', 'legal_entity_id' => $firstLegalEntity, 'responsible_org_unit_id' => $secondUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $crossSecond, 'tenant_id' => $this->tenantId, 'creation_key' => 'scope-d', 'kode' => 'AST-SCOPE-D', 'nama' => 'Aset scope D', 'legal_entity_id' => $secondLegalEntity, 'responsible_org_unit_id' => $firstUnit, ...$scopeClassification, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $lingkup = [
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $firstLegalEntity, 'organization_id' => $firstUnit],
            ['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $secondLegalEntity, 'organization_id' => $secondUnit],
        ];
        $data = $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'], $lingkup)->getJson('/api/modules/management-aset/v1/aset')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$first, $second], array_column($data, 'id'));
        $this->assertNotContains($crossFirst, array_column($data, 'id'));
        $this->assertNotContains($crossSecond, array_column($data, 'id'));
    }

    /**
     * Satu dokumen dekomisioning diajukan lalu disetujui, tanpa satu pun permintaan HTTP.
     *
     * Ini kriteria selesai F3-09, dan bentuk testnya sengaja berubah total dari pendahulunya.
     *
     * Test lama memalsukan Core dua kali: `Http::fake` memulangkan sebuah id instance karangan
     * untuk pengajuan, lalu test menyusun sendiri amplop keputusan dan mengirimkannya ke rute
     * panggilan balik module. Yang dibuktikannya cuma satu — module bisa membaca amplop yang
     * ditulis test itu sendiri. Tidak ada workflow yang pernah berjalan, tidak ada tugas yang
     * pernah menunggu, dan tidak ada seorang pun yang pernah menyetujui apa pun.
     *
     * Yang di sini menempuh jalur sungguhan dari ujung ke ujung: sebuah alur persetujuan
     * terbit untuk entitas legalnya, pengaju membuat dokumen, Core membuat tugas untuk
     * pemeriksa, pemeriksa menyetujui lewat layar Core, lalu keputusan itu sampai ke dokumen
     * module tanpa melewati satu baris HTTP pun.
     *
     * `preventStrayRequests()` yang membuat kalimat terakhir bisa gagal. Tanpa itu, sebuah
     * permintaan HTTP yang tersisa akan keluar diam-diam dan test tetap hijau.
     */
    public function test_dekomisioning_diajukan_lalu_disetujui_tanpa_permintaan_http(): void
    {
        Http::preventStrayRequests();

        $this->sebagaiPenggunaBernama('pemeriksa', $this->tenantId, []);
        $idPemeriksa = $this->idKeanggotaan('pemeriksa', $this->tenantId);

        $asetId = $this->receive();
        $aset = DB::table('aset_tr_aset')->where('id', $asetId)->first();
        $this->siapkanWorkflowDekomisioning($this->tenantId, (string) $aset->legal_entity_id, $idPemeriksa);

        $dokumen = $this->sebagaiPenggunaBernama('pengaju', $this->tenantId, ['management-aset.dekomisioning-aset.create'])
            ->withHeader('Idempotency-Key', 'decommission-1')
            ->postJson('/api/modules/management-aset/v1/dekomisioning-aset', [
                'legal_entity_id' => $aset->legal_entity_id,
                'responsible_org_unit_id' => $aset->responsible_org_unit_id,
                'tanggal' => '2026-08-03', 'aset_id' => $asetId,
            ])->assertCreated()->json('data');

        // Dokumen dan instance lahir bersama-sama. Sebelum F3-09 keduanya bisa terpisah:
        // dokumen tersimpan, pengajuannya gagal, dan barisnya menunggu persetujuan yang tidak
        // pernah diajukan siapa pun.
        $this->assertNotNull($dokumen['workflow_instance_id']);
        $this->assertSame('submitted', $dokumen['status']);

        $tugas = $this->tugasMenunggu($this->tenantId, $idPemeriksa);
        $this->assertNotNull($tugas, 'Tidak ada tugas persetujuan yang menunggu pemeriksa.');

        $this->sebagaiPenggunaBernama('pemeriksa', $this->tenantId, [])
            ->post('/workflow-inbox/'.$tugas->id.'/decision', ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertDatabaseHas('aset_tr_dokumen_siklus_aset', ['id' => $dokumen['id'], 'status' => 'approved']);
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $asetId, 'lifecycle_state' => 'decommissioned']);
        $this->assertDatabaseHas('workflow_instances', ['id' => $dokumen['workflow_instance_id'], 'status' => 'approved']);
        $this->assertDatabaseCount('aset_processed_core_events', 1);
    }

    /**
     * Alur tanpa langkah persetujuan selesai seketika, dan dokumennya ikut selesai.
     *
     * Graf Mulai → Selesai sah dan bisa diterbitkan admin; sebuah kondisi yang langsung menuju
     * Selesai menghasilkan bentuk yang sama. Instancenya `approved` **pada saat pengajuan**,
     * jadi keputusannya sampai ke listener sebelum pemanggil sempat menuliskan id instance ke
     * dokumen.
     *
     * Selama pengiriman keputusan berjalan lewat perintah terjadwal, urutan ini tidak pernah
     * muncul: keputusan baru dikirim berjam-jam kemudian, saat idnya sudah tersimpan. Ia hanya
     * terlihat setelah pengirimannya menjadi seketika — dan tanpa test ini, dokumennya akan
     * menggantung pada `submitted` sementara instancenya sudah `approved`.
     */
    public function test_alur_tanpa_langkah_persetujuan_langsung_menyelesaikan_dokumennya(): void
    {
        Http::preventStrayRequests();

        $asetId = $this->receive();
        $aset = DB::table('aset_tr_aset')->where('id', $asetId)->first();
        $this->siapkanWorkflowDekomisioning($this->tenantId, (string) $aset->legal_entity_id, null);

        $dokumen = $this->sebagaiPengguna($this->tenantId, ['management-aset.dekomisioning-aset.create'])
            ->withHeader('Idempotency-Key', 'decommission-langsung')
            ->postJson('/api/modules/management-aset/v1/dekomisioning-aset', [
                'legal_entity_id' => $aset->legal_entity_id,
                'responsible_org_unit_id' => $aset->responsible_org_unit_id,
                'tanggal' => '2026-08-03', 'aset_id' => $asetId,
            ])->assertCreated()->json('data');

        $this->assertSame('approved', $dokumen['status']);
        $this->assertNotNull($dokumen['workflow_instance_id']);
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $asetId, 'lifecycle_state' => 'decommissioned']);
    }

    /**
     * Dokumen dan nomornya ikut batal ketika alur persetujuannya belum disiapkan.
     *
     * Dulu pengajuan berjalan setelah dokumen tersimpan dan di luar transaksinya, jadi tenant
     * yang belum menyalakan alur persetujuan tetap mendapat dokumen — berstatus `submitted`,
     * bernomor, dan menunggu sesuatu yang tidak pernah ada. Nomornya ikut terpakai.
     *
     * Jawabannya juga bukan lagi 503. Core berada di proses yang sama, jadi "layanan
     * persetujuan belum dapat dihubungi" tidak pernah lagi benar.
     */
    public function test_dokumen_batal_ketika_alur_persetujuan_belum_disiapkan(): void
    {
        Http::preventStrayRequests();

        $asetId = $this->receive();
        $aset = DB::table('aset_tr_aset')->where('id', $asetId)->first();
        $sebelum = $this->jumlahNomorTerbit();

        $this->sebagaiPengguna($this->tenantId, ['management-aset.dekomisioning-aset.create'])
            ->withHeader('Idempotency-Key', 'decommission-tanpa-workflow')
            ->postJson('/api/modules/management-aset/v1/dekomisioning-aset', [
                'legal_entity_id' => $aset->legal_entity_id,
                'responsible_org_unit_id' => $aset->responsible_org_unit_id,
                'tanggal' => '2026-08-03', 'aset_id' => $asetId,
            ])->assertStatus(422);

        $this->assertSame(0, DB::table('aset_tr_dokumen_siklus_aset')->count(), 'Dokumen tersimpan padahal pengajuannya gagal.');
        $this->assertSame($sebelum, $this->jumlahNomorTerbit(), 'Nomor tetap terbit padahal dokumennya batal.');
    }

    /**
     * Group yang sengaja tidak disusutkan: buku tetap terbentuk sebagai baris subledger,
     * tetapi tidak menuntut profil apa pun dan tidak menahan aset di status `received`.
     */
    public function test_group_without_depreciation_still_lets_the_aset_be_placed(): void
    {
        $classification = $this->classification();
        $this->configureNonDepreciatingBook($classification['group_aset_id']);

        $asetId = $this->terimaAset($this->tenantId, [
            'legal_entity_id' => (string) Str::ulid(), 'nama' => 'Aset tanpa penyusutan', ...$classification,
            'acquired_on' => '2026-07-28', 'acquisition_value' => 9000000,
            'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
        ]);

        $this->assertDatabaseHas('aset_tr_buku_aset', [
            'aset_id' => $asetId, 'depreciate' => false, 'depreciation_profile_id' => null,
        ]);
    }

    /**
     * Ambang kapitalisasi memakai jalur yang sama: aset murah tidak menyusut, dan
     * karenanya juga tidak boleh dituntut punya profil yang berlaku saat ditempatkan.
     */
    public function test_aset_below_capitalization_threshold_is_placed_without_a_profile(): void
    {
        $classification = $this->classification();
        $this->configureNonDepreciatingBook($classification['group_aset_id'], depreciate: true);
        DB::table('aset_m_group_aset')
            ->where(['tenant_id' => $this->tenantId, 'id' => $classification['group_aset_id']])
            ->update(['capitalization_threshold' => 1000000]);

        $asetId = $this->terimaAset($this->tenantId, [
            'legal_entity_id' => (string) Str::ulid(), 'nama' => 'Aset di bawah ambang', ...$classification,
            'acquired_on' => '2026-07-28', 'acquisition_value' => 400000,
            'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
        ]);

        $this->assertDatabaseHas('aset_tr_buku_aset', ['aset_id' => $asetId, 'depreciate' => false]);
    }

    /** Buku tanpa profil yang memang menghitung tetap ditolak; pagar itu tidak ikut dilepas. */
    public function test_depreciating_book_without_a_profile_still_blocks_placement(): void
    {
        $classification = $this->classification();
        $this->configureNonDepreciatingBook($classification['group_aset_id'], depreciate: true);

        // Draf tetap boleh tersimpan: buku dan profil baru dituntut saat aset benar-benar
        // lahir, dan di situlah penolakannya jatuh.
        $penerimaan = $this->drafPenerimaan($this->tenantId, [
            'legal_entity_id' => (string) Str::ulid(), 'nama' => 'Aset tanpa profil', ...$classification,
            'acquired_on' => '2026-07-28', 'acquisition_value' => 9000000,
            'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
        ])->assertCreated()->json('data.id');

        $this->selesaikanPenerimaan($this->tenantId, (string) $penerimaan)->assertStatus(422);
        $this->assertSame(0, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $penerimaan)->count());
    }

    private function receive(): string
    {
        $classification = $this->classification();
        $this->configureReadyBook($classification['group_aset_id']);

        $this->legalEntityId = (string) Str::ulid();

        return $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $this->legalEntityId, 'nama' => 'Aset uji', ...$classification,
            'acquired_on' => '2026-07-28', 'acquisition_value' => 1, 'currency_code' => 'IDR', 'usage_org_unit_id' => (string) Str::ulid(),
        ]);
    }

    /**
     * Dua sumbu klasifikasi wajib milik aset, keduanya datar dan saling lepas.
     *
     * @return array{group_aset_id: string, jenis_aset_id: string}
     */
    private function classification(): array
    {
        $group = (string) Str::ulid();
        $type = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.Str::ulid(), 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $type];
    }

    private function configureReadyBook(string $groupId): void
    {
        $now = now();
        $profile = (string) Str::ulid();
        $book = (string) Str::ulid();
        DB::table('aset_m_profil_penyusutan')->insert([
            'id' => $profile, 'tenant_id' => $this->tenantId, 'creation_key' => 'profile-ready-'.Str::ulid(),
            'kode' => 'P'.Str::random(8), 'nama' => 'Profil siap', 'aktif' => true,
            'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar',
            'useful_life_periods' => 12, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $book, 'tenant_id' => $this->tenantId, 'creation_key' => 'book-ready-'.Str::ulid(),
            'kode' => 'B'.Str::random(8), 'nama' => 'Buku siap', 'aktif' => true,
            'posting_layer' => 'current', 'depreciation_profile_id' => $profile,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $groupId,
            'buku_id' => $book, 'depreciate' => true, 'useful_life_periods' => 12,
            'convention' => 'full_month', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Buku tanpa profil sama sekali. `depreciate` dibiarkan dapat dinyalakan supaya test
     * yang sama dapat menguji dua sisi pagar: buku yang memang tidak menghitung, dan
     * buku yang menghitung tetapi profilnya belum dipilih.
     */
    private function configureNonDepreciatingBook(string $groupId, bool $depreciate = false): void
    {
        $now = now();
        $book = (string) Str::ulid();
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $book, 'tenant_id' => $this->tenantId, 'creation_key' => 'book-register-'.Str::ulid(),
            'kode' => 'B'.Str::random(8), 'nama' => 'Buku register', 'aktif' => true,
            'posting_layer' => 'current', 'depreciation_profile_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $groupId,
            'buku_id' => $book, 'depreciate' => $depreciate, 'useful_life_periods' => null,
            'convention' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
