<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Reporting\PenyediaLaporan;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

/**
 * Laporan module seperti yang dibaca mesin laporan Core: definisi, layout bawaan, dan dataset.
 *
 * Dulu ketiganya endpoint HTTP `internal/v1/laporan/...` yang dipanggil Core dengan token
 * konteks pengguna. Sejak F3-12 Core membacanya langsung lewat `PenyediaLaporan` di dalam
 * proses yang sama, jadi test ini memanggil penyedianya, bukan rutenya.
 *
 * Yang diuji tidak berubah: module menegakkan permission dan scope organisasi pada jalur ini
 * persis seperti pada layar. Yang berubah, konteksnya datang sebagai argumen — bentuknya sama
 * dengan yang dulu dibawa token, dan itu memang alasan bentuknya dipertahankan.
 */
class PenyediaLaporanTest extends TestCase
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
    }

    public function test_definisi_menyebut_placeholder_dan_layout_bawaan(): void
    {
        $definisi = $this->penyedia()->definisi('work-order', $this->konteks(['management-aset.pemeliharaan-aset.read']));

        $this->assertSame(['id'], $definisi['parameters']);
        $this->assertContains(
            ['key' => 'baris.asset_kode', 'label' => 'Kode aset', 'table' => 'baris'],
            $definisi['fields'],
        );

        $isi = $this->penyedia()->layoutBawaan('work-order', 'standar', $this->konteks(['management-aset.pemeliharaan-aset.read']));
        $this->assertNotSame('', $isi, 'Berkas layout bawaan terbaca kosong dari folder module.');

        $this->assertFalse($this->penyedia()->punya('tidak-ada'));
    }

    public function test_dataset_menuntut_izin_data_dan_menghormati_scope_organisasi(): void
    {
        $workOrder = $this->workOrder();

        // Izin yang salah ditolak, dan penolakannya berbunyi seperti yang dilihat pengguna.
        // Core sudah memeriksa "boleh menjalankan laporan ini"; yang diperiksa di sini adalah
        // "boleh membaca data yang dilaporkan", dan keduanya memang pertanyaan berbeda.
        $this->assertGagalDengan(
            'Anda tidak berhak membaca data laporan ini.',
            fn () => $this->penyedia()->dataset('work-order', $this->konteks(['management-aset.aset.read']), ['id' => $workOrder]),
        );

        $data = $this->penyedia()->dataset(
            'work-order',
            $this->konteks(['management-aset.pemeliharaan-aset.read']),
            ['id' => $workOrder],
        );

        $this->assertSame('PMHA-000001', $data['fields']['kode']);
        $this->assertSame('Korektif', $data['fields']['tipe_work_order']);
        $this->assertSame('AST-WO-1', $data['tables']['baris'][0]['asset_kode']);
        $this->assertSame('Ganti ban', $data['tables']['baris'][0]['jenis_pekerjaan']);
        $this->assertSame('PMHA-000001', $data['file_name']);

        // Di luar scope organisasi pengguna: pesan yang sama dengan layar. Core mengubahnya
        // menjadi baris ekspor yang gagal dengan pesan itu, bukan menjadi kesalahan server.
        $this->assertGagalDengan(
            'Work order tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.',
            fn () => $this->penyedia()->dataset(
                'work-order',
                $this->konteks(['management-aset.pemeliharaan-aset.read'], lingkupLain: true),
                ['id' => $workOrder],
            ),
        );

        $this->assertGagalDengan(
            'Parameter laporan tidak diterima',
            fn () => $this->penyedia()->dataset(
                'work-order',
                $this->konteks(['management-aset.pemeliharaan-aset.read']),
                ['id' => 'bukan-ulid'],
            ),
        );
    }

    public function test_daftar_work_order_tersaring_status_dan_satu_baris_per_work_order(): void
    {
        $this->workOrder();
        $konteks = $this->konteks(['management-aset.pemeliharaan-aset.read']);

        $draft = $this->penyedia()->dataset('daftar-work-order', $konteks, ['status' => 'draft']);
        $this->assertSame(1, $draft['fields']['jumlah_work_order']);
        $this->assertSame('PMHA-000001', $draft['tables']['baris'][0]['kode']);
        $this->assertSame(1, $draft['tables']['baris'][0]['jumlah_baris']);

        $ditutup = $this->penyedia()->dataset('daftar-work-order', $konteks, ['status' => 'ditutup']);
        $this->assertSame(0, $ditutup['fields']['jumlah_work_order']);
        $this->assertSame([], $ditutup['tables']['baris']);
    }

    /** @param list<string> $permissions */
    private function headers(array $permissions): static
    {
        return $this->sebagaiPengguna($this->tenantId, $permissions);
    }

    /**
     * Penyedia laporan module dengan tenant aktif terikat.
     *
     * Tenantnya diikat di sini karena begitulah Core memanggilnya: mesin laporan berjalan di
     * worker antrean yang tidak pernah melewati middleware konteks module. Memanggil tanpa
     * ikatan akan lulus atau gagal tergantung permintaan HTTP mana yang kebetulan berjalan
     * sebelumnya di test yang sama.
     */
    private function penyedia(): PenyediaLaporanTerikat
    {
        return new PenyediaLaporanTerikat(
            $this->app->make(PenyediaLaporan::class),
            $this->app->make(PelaksanaUntukTenant::class),
            $this->tenantId,
        );
    }

    /**
     * Konteks seperti yang disusun Core dari keanggotaan pengguna.
     *
     * Bentuknya sama persis dengan yang dulu dibawa token konteks, dan itu disengaja: yang
     * berpindah pada F3-12 adalah pengantarnya, bukan isinya.
     *
     * @param  list<string>  $izin
     * @return array<string, mixed>
     */
    private function konteks(array $izin, bool $lingkupLain = false): array
    {
        $kebijakan = $lingkupLain
            ? ['all' => false, 'scope_grants' => [[
                'legal_entity_id' => $this->legalEntityId,
                'operating_unit_ids' => [(string) Str::ulid()],
            ]]]
            : ['all' => true, 'scope_grants' => []];

        return [
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
            'org_unit_id' => $this->orgUnitId,
            'user_id' => (string) Str::ulid(),
            'permissions' => $izin,
            'data_policies' => ['management-aset.asset-responsibility' => $kebijakan],
        ];
    }

    /** @param callable(): mixed $aksi */
    private function assertGagalDengan(string $potonganPesan, callable $aksi): void
    {
        try {
            $aksi();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($potonganPesan, $e->getMessage());

            return;
        }

        $this->fail('Panggilan yang seharusnya ditolak justru berhasil; yang diharapkan pesan berisi: '.$potonganPesan);
    }

    /** Work order draf dengan satu baris pekerjaan, dibuat lewat API seperti pengguna. */
    private function workOrder(): string
    {
        $seed = [
            'tipe' => $this->master('aset_m_tipe_work_order', 'Korektif', 'TPWO-1'),
            'layanan' => $this->master('aset_m_tingkat_layanan', 'Mendesak', 'TGLY-1', ['urutan' => 1]),
            'trade' => $this->master('aset_m_trade', 'Mekanik', 'TRDE-1'),
            'jobType' => $this->master('aset_m_maintenance_job_type', 'Ganti ban', 'JOB-1', ['category_code' => 'corrective']),
            'group' => $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-1'),
            'jenis' => $this->master('aset_m_jenis_aset', 'Kendaraan roda 4', 'JNSA-1'),
            'tipeLokasi' => $this->master('aset_m_tipe_lokasi_aset', 'Gudang', 'TLKA-1'),
        ];
        $locationId = (string) Str::ulid();
        DB::table('aset_m_lokasi_aset')->insert([
            'id' => $locationId, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'LOCA-1', 'nama' => 'Gudang Cakung', 'tipe_lokasi_id' => $seed['tipeLokasi'], 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $assetId = (string) Str::ulid();
        DB::table('aset_tr_penerimaan_aset')->insert([
            'id' => $assetId, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => 'AST-WO-1',
            'nama' => 'Forklift 1', 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $seed['group'], 'jenis_aset_id' => $seed['jenis'], 'asset_location_id' => $locationId,
            'acquired_on' => '2026-08-01', 'acquisition_value' => 250000000, 'currency_code' => 'IDR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->headers(['management-aset.pemeliharaan-aset.create'])
            ->withHeader('Idempotency-Key', 'wo-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset', [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'tipe_work_order_id' => $seed['tipe'],
                'tingkat_layanan_id' => $seed['layanan'],
                'keterangan' => 'Ban depan kanan bocor',
                'diharapkan_mulai' => '2026-08-15 08:00:00',
                'diharapkan_selesai' => '2026-08-15 12:00:00',
                'details' => [[
                    'asset_id' => $assetId, 'maintenance_job_type_id' => $seed['jobType'], 'trade_id' => $seed['trade'],
                    'ditugaskan_ke_user_id' => 'montir-1', 'estimasi_jam' => 1.5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true, ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}

/**
 * Penyedia laporan yang setiap panggilannya berjalan dengan tenant aktif terikat.
 *
 * Ini yang dikerjakan `SumberLaporan` milik Core sebelum menyerahkan panggilan ke module.
 * Ditiru di sini supaya test menempuh keadaan yang sama, bukan keadaan yang kebetulan
 * tersisa dari permintaan HTTP sebelumnya.
 */
final class PenyediaLaporanTerikat
{
    public function __construct(
        private readonly PenyediaLaporan $penyedia,
        private readonly PelaksanaUntukTenant $pelaksana,
        private readonly string $tenantId,
    ) {}

    public function punya(string $kode): bool
    {
        return $this->penyedia->punya($kode);
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @return array<string, mixed>
     */
    public function definisi(string $kode, array $konteks): array
    {
        return $this->pelaksana->jalankanUntuk($this->tenantId, fn (): array => $this->penyedia->definisi($kode, $konteks));
    }

    /** @param array<string, mixed> $konteks */
    public function layoutBawaan(string $kode, string $kunci, array $konteks): string
    {
        return $this->pelaksana->jalankanUntuk($this->tenantId, fn (): string => $this->penyedia->layoutBawaan($kode, $kunci, $konteks));
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @param  array<string, mixed>  $parameter
     * @return array<string, mixed>
     */
    public function dataset(string $kode, array $konteks, array $parameter): array
    {
        return $this->pelaksana->jalankanUntuk($this->tenantId, fn (): array => $this->penyedia->dataset($kode, $konteks, $parameter));
    }
}
