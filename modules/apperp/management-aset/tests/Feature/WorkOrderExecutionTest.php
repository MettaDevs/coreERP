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

class WorkOrderExecutionTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const SEMUA = [
        'management-aset.pemeliharaan-aset.read',
        'management-aset.pemeliharaan-aset.create',
        'management-aset.pemeliharaan-aset.update',
        'management-aset.pemeliharaan-aset.schedule',
        'management-aset.pemeliharaan-aset.execute',
        'management-aset.pemeliharaan-aset.close',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    /** @var null|array<string, string> */
    private ?array $tersemai = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        // Nomor harus berurut: satu test dapat membuat lebih dari satu work order, dan dua
        // nomor yang sama akan ditolak unique (tenant_id, kode) persis seperti di produksi.
        $terbit = 0;
        Http::fake(function () use (&$terbit) {
            $terbit++;

            return Http::response(['data' => ['number' => sprintf('PMHA-%06d', $terbit)]], 200);
        });
    }

    /**
     * Master disemai saat pertama dipakai supaya kegagalan seeding tampil di titik pemakaiannya.
     *
     * @return array<string, string>
     */
    private function masters(): array
    {
        return $this->tersemai ??= $this->seedMasters();
    }

    public function test_menolak_penjadwalan_tanpa_tanggal_mulai_dan_menerimanya_setelah_diisi(): void
    {
        $workOrder = $this->buatWorkOrder(['dijadwalkan_mulai' => null]);

        $this->pindah($workOrder['id'], 'dijadwalkan', 1)
            ->assertUnprocessable()->assertJsonValidationErrors('ke_status');
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset', ['id' => $workOrder['id'], 'status' => 'draft']);

        $terjadwal = $this->buatWorkOrder();
        $this->pindah($terjadwal['id'], 'dijadwalkan', 1)->assertOk()->assertJsonPath('data.status', 'dijadwalkan');
    }

    public function test_transisi_menuntut_izin_yang_tepat_untuk_setiap_langkah(): void
    {
        $workOrder = $this->buatWorkOrder();

        // Teknisi boleh mengerjakan, tetapi tidak boleh menjadwalkan.
        $this->pindah($workOrder['id'], 'dijadwalkan', 1, ['management-aset.pemeliharaan-aset.execute'])->assertForbidden();
        $this->pindah($workOrder['id'], 'dijadwalkan', 1, ['management-aset.pemeliharaan-aset.schedule'])->assertOk();
        // Penjadwal tidak boleh mulai mengerjakan.
        $this->pindah($workOrder['id'], 'dikerjakan', 2, ['management-aset.pemeliharaan-aset.schedule'])->assertForbidden();
        $this->pindah($workOrder['id'], 'dikerjakan', 2, ['management-aset.pemeliharaan-aset.execute'])->assertOk();
    }

    public function test_transisi_yang_melompati_status_ditolak(): void
    {
        $workOrder = $this->buatWorkOrder();

        $this->pindah($workOrder['id'], 'selesai', 1)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'transisi_tidak_sah');
    }

    public function test_pembatalan_menuntut_alasan_dan_tercatat_pada_status_log(): void
    {
        $workOrder = $this->buatWorkOrder();

        $this->pindah($workOrder['id'], 'dibatalkan', 1)
            ->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->pindah($workOrder['id'], 'dibatalkan', 1, alasan: 'Aset sudah dijual')->assertOk();

        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_status_log', [
            'pemeliharaan_aset_id' => $workOrder['id'], 'dari_status' => 'draft',
            'ke_status' => 'dibatalkan', 'alasan' => 'Aset sudah dijual', 'oleh_user_id' => $this->idPengguna('penyelia-1'),
        ]);
    }

    public function test_tidak_dapat_selesai_selama_pemeriksaan_wajib_kosong_dan_tidak_berlaku_membukanya(): void
    {
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);

        $this->pindah($workOrder['id'], 'selesai', 3)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ke_status');

        // Menandai tidak berlaku membuka gate tanpa memalsukan nilai pemeriksaan.
        $wajib = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where(['pemeliharaan_aset_detail_id' => $jobId, 'wajib' => true])->pluck('id');
        $this->simpanChecklist($workOrder['id'], $jobId, $wajib->map(fn (string $id): array => [
            'id' => $id, 'tidak_berlaku' => true,
        ])->all())->assertOk();

        $this->pindah($workOrder['id'], 'selesai', 3)->assertOk()->assertJsonPath('data.status', 'selesai');
        $this->assertNotNull(DB::table('aset_tr_pemeliharaan_aset')->where('id', $workOrder['id'])->value('aktual_selesai'));
    }

    public function test_aturan_status_dapat_mewajibkan_sebab_dan_tindakan_sebelum_selesai(): void
    {
        $this->aturan('selesai', 'sebab_kerusakan', true, 'error');
        $this->aturan('selesai', 'tindakan_perbaikan', true, 'error');
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        // Gate checklist dilewati lebih dahulu supaya yang diuji di sini benar-benar gate
        // sebab dan tindakan, bukan pemeriksaan wajib yang kebetulan juga masih kosong.
        $this->tuntaskanChecklist($workOrder['id'], $jobId);

        $this->pindah($workOrder['id'], 'selesai', 3)
            ->assertUnprocessable()->assertJsonValidationErrors('ke_status');

        DB::table('aset_tr_pemeliharaan_aset_details')->where('id', $jobId)->update([
            'sebab_kerusakan_id' => $this->master('aset_m_sebab_kerusakan', 'Aus wajar', 'SBKR-1'),
            'tindakan_perbaikan_id' => $this->master('aset_m_tindakan_perbaikan', 'Ganti komponen', 'TDPB-1'),
        ]);

        $this->pindah($workOrder['id'], 'selesai', 3)->assertOk();
    }

    public function test_aturan_berkeparahan_peringatan_membiarkan_transisi_tetapi_meninggalkan_jejak(): void
    {
        $this->aturan('selesai', 'sebab_kerusakan', true, 'peringatan');
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $this->tuntaskanChecklist($workOrder['id'], $jobId);

        // Sebab kerusakan sengaja dibiarkan kosong: peringatan tidak boleh menahan.
        $this->pindah($workOrder['id'], 'selesai', 3)->assertOk()->assertJsonPath('data.status', 'selesai');

        $log = DB::table('aset_tr_pemeliharaan_aset_status_log')
            ->where(['pemeliharaan_aset_id' => $workOrder['id'], 'ke_status' => 'selesai'])->first();
        $this->assertStringContainsString('Sebab kerusakan belum diisi', (string) $log->peringatan);
    }

    public function test_aturan_yang_dimatikan_tidak_menahan_apa_pun(): void
    {
        // Dimatikan setelah work order dibuat: seed default menyalakan aturan ini saat
        // master pertama kali disemai, jadi mematikannya lebih dahulu akan tertimpa.
        $workOrder = $this->siapDikerjakan();
        $this->aturan('selesai', 'checklist_wajib', false, 'error');

        // Pemeriksaan wajib masih kosong, tetapi aturannya dimatikan tenant.
        $this->pindah($workOrder['id'], 'selesai', 3)->assertOk()->assertJsonPath('data.status', 'selesai');
    }

    public function test_salin_template_memekarkan_template_bersarang_dan_menomori_ulang(): void
    {
        $workOrder = $this->buatWorkOrder();
        $jobId = $this->jobId($workOrder['id']);

        $this->salinTemplate($workOrder['id'], $jobId)->assertCreated();

        $baris = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where('pemeliharaan_aset_detail_id', $jobId)->orderBy('line_number')->get();

        // Template induk berisi 2 baris + 1 baris bersarang yang memuat 2 baris lagi.
        $this->assertSame(['Tekanan ban depan', 'Kondisi alur ban', 'Cek baut roda', 'Cek rem'], $baris->pluck('nama')->all());
        $this->assertSame(['1.0', '2.0', '3.0', '4.0'], $baris->pluck('line_number')->map(fn ($n): string => (string) (float) $n === (string) (int) $n ? number_format((float) $n, 1, '.', '') : (string) $n)->all());
        $this->assertSame('template', $baris->first()->sumber);
        // Instruksi ikut tersalin; tanpa ini teknisi membaca nama pemeriksaan tanpa tahu
        // caranya, dan kolom instruksi pada hasil pemeriksaan tidak akan pernah terisi.
        $this->assertSame('Ukur saat ban dingin.', $baris->first()->instruksi);
    }

    public function test_default_job_type_menyalin_checklist_saat_work_order_dibuat(): void
    {
        DB::table('aset_m_maintenance_job_type_default')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'DFLT-1',
            'nama' => 'Checklist bawaan Ganti ban',
            'maintenance_job_type_id' => $this->masters()['jobType'],
            'checklist_template_id' => $this->masters()['template'],
            'hours' => 1,
            'items_count' => 0,
            'expenses_count' => 0,
            'fees_count' => 0,
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workOrder = $this->buatWorkOrder();
        $jobId = $this->jobId($workOrder['id']);

        $this->assertSame(
            ['Tekanan ban depan', 'Kondisi alur ban', 'Cek baut roda', 'Cek rem'],
            DB::table('aset_tr_pemeliharaan_aset_checklist')->where('pemeliharaan_aset_detail_id', $jobId)
                ->orderBy('line_number')->pluck('nama')->all(),
        );
    }

    public function test_hasil_baris_pekerjaan_diturunkan_dari_hasil_pemeriksaan(): void
    {
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $baris = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where('pemeliharaan_aset_detail_id', $jobId)->orderBy('line_number')->get()->keyBy('tipe');

        $this->simpanChecklist($workOrder['id'], $jobId, [
            ['id' => $baris['measurement']->id, 'nilai' => '32'],
            // "Botak" bernilai fail pada variabelnya, jadi kesimpulan baris harus gagal
            // walaupun tidak ada satu pun kolom yang diketik teknisi sebagai "gagal".
            ['id' => $baris['variable']->id, 'nilai' => 'Botak'],
        ])->assertOk();

        $this->pindah($workOrder['id'], 'selesai', 3)->assertOk();
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_details', ['id' => $jobId, 'hasil' => 'gagal']);
    }

    public function test_hasil_lulus_ketika_seluruh_pemeriksaan_berlaku_tidak_gagal(): void
    {
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $baris = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where('pemeliharaan_aset_detail_id', $jobId)->orderBy('line_number')->get()->keyBy('tipe');

        $this->simpanChecklist($workOrder['id'], $jobId, [
            ['id' => $baris['measurement']->id, 'nilai' => '32'],
            ['id' => $baris['variable']->id, 'nilai' => 'Baik'],
        ])->assertOk();

        $this->pindah($workOrder['id'], 'selesai', 3)->assertOk();
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_details', ['id' => $jobId, 'hasil' => 'lulus']);
    }

    public function test_pengukuran_di_luar_rentang_template_menjadi_gagal(): void
    {
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $baris = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where('pemeliharaan_aset_detail_id', $jobId)->orderBy('line_number')->get()->keyBy('tipe');

        $this->simpanChecklist($workOrder['id'], $jobId, [
            ['id' => $baris['measurement']->id, 'nilai' => '40'],
            ['id' => $baris['variable']->id, 'nilai' => 'Baik'],
        ])->assertOk();

        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_checklist', [
            'id' => $baris['measurement']->id, 'result_code' => 'fail', 'min_value' => 30, 'max_value' => 35,
        ]);
    }

    public function test_hasil_tidak_dinilai_mewajibkan_catatan_dan_tidak_menjadi_lulus(): void
    {
        DB::table('aset_m_maintenance_checklist_variable_value')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'variable_id' => $this->masters()['variable'],
            'line_number' => 4, 'value' => 'Belum dapat diperiksa', 'result_code' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $baris = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where('pemeliharaan_aset_detail_id', $jobId)->orderBy('line_number')->get()->keyBy('tipe');

        $this->simpanChecklist($workOrder['id'], $jobId, [
            ['id' => $baris['measurement']->id, 'nilai' => '32'],
            ['id' => $baris['variable']->id, 'nilai' => 'Belum dapat diperiksa'],
        ])->assertUnprocessable()->assertJsonValidationErrors('baris');

        $this->simpanChecklist($workOrder['id'], $jobId, [
            ['id' => $baris['measurement']->id, 'nilai' => '32'],
            ['id' => $baris['variable']->id, 'nilai' => 'Belum dapat diperiksa', 'catatan_teknisi' => 'Kendaraan tidak dapat dinyalakan.'],
        ])->assertOk();
        $this->pindah($workOrder['id'], 'selesai', 3)->assertOk();
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_details', ['id' => $jobId, 'hasil' => 'tidak_dinilai']);
    }

    public function test_nilai_di_luar_pilihan_variabel_ditolak_dan_yang_sah_menyimpan_result_code(): void
    {
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $pilihan = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where(['pemeliharaan_aset_detail_id' => $jobId, 'tipe' => 'variable'])->first();

        $this->simpanChecklist($workOrder['id'], $jobId, [['id' => $pilihan->id, 'nilai' => 'Meledak']])
            ->assertUnprocessable()->assertJsonValidationErrors('baris');

        $this->simpanChecklist($workOrder['id'], $jobId, [['id' => $pilihan->id, 'nilai' => 'Botak']])->assertOk();
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_checklist', [
            'id' => $pilihan->id, 'nilai' => 'Botak', 'result_code' => 'fail', 'diperiksa' => true,
        ]);
    }

    public function test_hasil_pelaksanaan_menyimpan_jam_sebab_dan_tindakan_per_baris(): void
    {
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $sebab = $this->master('aset_m_sebab_kerusakan', 'Ban aus', 'SBKR-1');
        $tindakan = $this->master('aset_m_tindakan_perbaikan', 'Ganti ban', 'TDPB-1');

        $this->headers(self::SEMUA, 'montir-1')
            ->patchJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'].'/jobs/'.$jobId.'/execution', [
                'aktual_jam' => 2.25,
                'sebab_kerusakan_id' => $sebab,
                'tindakan_perbaikan_id' => $tindakan,
            ])
            ->assertOk();

        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_details', [
            'id' => $jobId,
            'aktual_jam' => 2.25,
            'sebab_kerusakan_id' => $sebab,
            'tindakan_perbaikan_id' => $tindakan,
        ]);

        DB::table('aset_m_sebab_kerusakan')->where('id', $sebab)->update(['aktif' => false, 'deleted_at' => now()]);
        DB::table('aset_m_tindakan_perbaikan')->where('id', $tindakan)->update(['aktif' => false, 'deleted_at' => now()]);

        $this->headers(self::SEMUA, 'montir-1')
            ->getJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'])
            ->assertOk()
            ->assertJsonPath('data.details.0.sebab_kerusakan_nama', 'Ban aus')
            ->assertJsonPath('data.details.0.tindakan_perbaikan_nama', 'Ganti ban');
    }

    public function test_checklist_hanya_dapat_diisi_saat_pekerjaan_sedang_dikerjakan(): void
    {
        $workOrder = $this->buatWorkOrder();
        $jobId = $this->jobId($workOrder['id']);
        $this->salinTemplate($workOrder['id'], $jobId)->assertCreated();
        $baris = DB::table('aset_tr_pemeliharaan_aset_checklist')->where('pemeliharaan_aset_detail_id', $jobId)->first();

        $this->simpanChecklist($workOrder['id'], $jobId, [['id' => $baris->id, 'nilai' => '32']])
            ->assertUnprocessable();
    }

    public function test_pilihan_lainnya_mewajibkan_dan_menyimpan_keterangan(): void
    {
        $workOrder = $this->siapDikerjakan();
        $jobId = $this->jobId($workOrder['id']);
        $sebab = $this->master('aset_m_sebab_kerusakan', 'Lainnya', 'SBKR-LAIN');
        DB::table('aset_m_sebab_kerusakan')->where('id', $sebab)->update(['minta_keterangan' => true]);

        $request = fn (?string $keterangan) => $this->headers(self::SEMUA, 'montir-1')
            ->patchJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'].'/jobs/'.$jobId.'/execution', [
                'sebab_kerusakan_id' => $sebab,
                'sebab_kerusakan_keterangan' => $keterangan,
            ]);

        $request(null)->assertUnprocessable()->assertJsonValidationErrors('sebab_kerusakan_keterangan');
        $request('Retak akibat benturan')->assertOk();
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_details', [
            'id' => $jobId,
            'sebab_kerusakan_id' => $sebab,
            'sebab_kerusakan_keterangan' => 'Retak akibat benturan',
        ]);
    }

    public function test_pekerjaan_saya_hanya_menampilkan_baris_milik_pengguna_yang_sedang_berjalan(): void
    {
        $milikSaya = $this->siapDikerjakan();
        $orangLain = $this->buatWorkOrder(['ditugaskan_ke' => 'montir-2']);
        $this->pindah($orangLain['id'], 'dijadwalkan', 1)->assertOk();

        $data = $this->headers([...self::SEMUA], 'montir-1')
            ->getJson('/api/modules/management-aset/v1/pemeliharaan-aset/saya')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($milikSaya['id'], $data[0]['pemeliharaan_aset_id']);
        $this->assertSame('AST-WO-1', $data[0]['aset_kode']);
    }

    // ---------- alur bantu ----------

    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function buatWorkOrder(array $ubah = []): array
    {
        return $this->headers(self::SEMUA, 'montir-1')
            ->withHeader('Idempotency-Key', 'wo-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset', $this->payload($ubah))
            ->assertCreated()->json('data');
    }

    /**
     * Work order yang checklist-nya sudah disusun, lalu dijadwalkan dan mulai dikerjakan;
     * version berakhir di 3. Checklist sengaja disalin lebih dahulu karena prosedur disusun
     * sebelum pekerjaan dimulai, bukan setelahnya.
     *
     * @return array<string, mixed>
     */
    private function siapDikerjakan(?string $tipe = null): array
    {
        $workOrder = $this->buatWorkOrder($tipe ? ['tipe' => $tipe] : []);
        $this->salinTemplate($workOrder['id'], $this->jobId($workOrder['id']))->assertCreated();
        $this->pindah($workOrder['id'], 'dijadwalkan', 1)->assertOk();
        $this->pindah($workOrder['id'], 'dikerjakan', 2)->assertOk();

        return $workOrder;
    }

    /**
     * @param  list<string>|null  $permissions
     * @return TestResponse<Response>
     */
    private function pindah(string $id, string $ke, int $version, ?array $permissions = null, ?string $alasan = null): TestResponse
    {
        return $this->headers($permissions ?? self::SEMUA, 'penyelia-1')
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$id.'/status', array_filter([
                'ke_status' => $ke, 'version' => $version, 'alasan' => $alasan,
            ], static fn ($value) => $value !== null));
    }

    /** @return TestResponse<Response> */
    private function salinTemplate(string $id, string $jobId): TestResponse
    {
        return $this->headers(self::SEMUA, 'montir-1')
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$id.'/jobs/'.$jobId.'/checklist/dari-template', [
                'template_id' => $this->masters()['template'],
            ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $baris
     * @return TestResponse<Response>
     */
    private function simpanChecklist(string $id, string $jobId, array $baris): TestResponse
    {
        return $this->headers(self::SEMUA, 'montir-1')
            ->putJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$id.'/jobs/'.$jobId.'/checklist', ['baris' => $baris]);
    }

    /** Menandai seluruh pemeriksaan wajib sebagai tidak berlaku supaya gate lain dapat diuji sendiri. */
    private function tuntaskanChecklist(string $workOrderId, string $jobId): void
    {
        $wajib = DB::table('aset_tr_pemeliharaan_aset_checklist')
            ->where(['pemeliharaan_aset_detail_id' => $jobId, 'wajib' => true])->pluck('id');
        $this->simpanChecklist($workOrderId, $jobId, $wajib->map(fn (string $id): array => [
            'id' => $id, 'tidak_berlaku' => true,
        ])->all())->assertOk();
    }

    private function jobId(string $workOrderId): string
    {
        return (string) DB::table('aset_tr_pemeliharaan_aset_details')->where('pemeliharaan_aset_id', $workOrderId)->value('id');
    }

    /**
     * Identitas pengguna tidak lagi dioper sebagai klaim; tiap pemanggilan membuat pengguna
     * sungguhan di Core. Parameter lama dibuang karena nilainya tidak lagi menentukan apa pun.
     *
     * @param  list<string>  $permissions
     */
    private function headers(array $permissions, string $sebagai = 'penyelia-1'): static
    {
        return $this->sebagaiPenggunaBernama($sebagai, $this->tenantId, $permissions);
    }

    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function payload(array $ubah = []): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId,
            'responsible_org_unit_id' => $this->orgUnitId,
            'tipe_work_order_id' => $ubah['tipe'] ?? $this->masters()['tipe'],
            'keterangan' => 'Ban depan kanan bocor',
            'dijadwalkan_mulai' => array_key_exists('dijadwalkan_mulai', $ubah) ? $ubah['dijadwalkan_mulai'] : '2026-08-16 08:00:00',
            'details' => [[
                'aset_id' => $this->masters()['aset'],
                'maintenance_job_type_id' => $this->masters()['jobType'],
                // Penugasan memakai id pengguna sungguhan; 'montir-1' hanya nama panggilan di test.
                'ditugaskan_ke_user_id' => $ubah['ditugaskan_ke'] ?? $this->idPengguna('montir-1'),
            ]],
        ];
    }

    // ---------- seed ----------

    /** @return array<string, string> */
    private function seedMasters(): array
    {
        $semai = [
            'tipe' => $this->master('aset_m_tipe_work_order', 'Korektif', 'TPWO-1'),
            'jobType' => $this->master('aset_m_maintenance_job_type', 'Ganti ban', 'JOB-1', ['category_code' => 'corrective']),
            'group' => $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-1'),
            'jenis' => $this->master('aset_m_jenis_aset', 'Kendaraan roda 4', 'JNSA-1'),
        ];
        $semai['variable'] = $this->variabel();
        $semai['nested'] = $this->template('Rem dan roda', 'TCMA-2', [
            ['line_number' => 1, 'type' => 'text', 'nama' => 'Cek baut roda', 'wajib' => false],
            ['line_number' => 2, 'type' => 'text', 'nama' => 'Cek rem', 'wajib' => false],
        ]);
        $semai['template'] = $this->template('Perawatan ban', 'TCMA-1', [
            ['line_number' => 1, 'type' => 'measurement', 'nama' => 'Tekanan ban depan', 'unit' => 'psi', 'min_value' => 30, 'max_value' => 35, 'wajib' => true, 'instruksi' => 'Ukur saat ban dingin.'],
            ['line_number' => 2, 'type' => 'variable', 'nama' => 'Kondisi alur ban', 'variable_id' => $semai['variable'], 'wajib' => true],
            ['line_number' => 3, 'type' => 'template', 'nama' => 'Rem dan roda', 'nested_template_id' => $semai['nested'], 'wajib' => false],
        ]);
        $semai['aset'] = $this->aset($semai);
        // Tenant nyata menerima aturan ini lewat provisioning; test menyemainya sendiri
        // karena ia membuat tenant secara langsung tanpa melewati jalur itu.
        $this->aturan('selesai', 'checklist_wajib', true, 'error');

        return $semai;
    }

    private function variabel(): string
    {
        $id = $this->master('aset_m_maintenance_checklist_variable', 'Kondisi alur', 'VCMA-1');
        // Hasil nilai variabel mengikuti tiga hasil F&O: `pass`, `fail`, dan `none`.
        foreach ([['Baik', 'pass', 1], ['Aus', 'fail', 2], ['Botak', 'fail', 3]] as [$nilai, $code, $urutan]) {
            DB::table('aset_m_maintenance_checklist_variable_value')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'variable_id' => $id,
                'line_number' => $urutan, 'value' => $nilai, 'result_code' => $code,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** @param list<array<string, mixed>> $lines */
    private function template(string $nama, string $kode, array $lines): string
    {
        $id = $this->master('aset_m_maintenance_checklist_template', $nama, $kode);
        foreach ($lines as $line) {
            DB::table('aset_m_maintenance_checklist_template_line')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'template_id' => $id,
                'line_number' => $line['line_number'], 'type' => $line['type'], 'nama' => $line['nama'],
                'unit' => $line['unit'] ?? null, 'min_value' => $line['min_value'] ?? null, 'max_value' => $line['max_value'] ?? null, 'variable_id' => $line['variable_id'] ?? null,
                'instruksi' => $line['instruksi'] ?? null,
                'nested_template_id' => $line['nested_template_id'] ?? null, 'wajib' => $line['wajib'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** @param array<string, string> $semai */
    private function aset(array $semai): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => 'AST-WO-1',
            'nama' => 'Aset work order eksekusi',
            'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $semai['group'], 'jenis_aset_id' => $semai['jenis'],
            'acquired_on' => '2026-08-01', 'acquisition_value' => 250000000, 'currency_code' => 'IDR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Menyalakan atau mengubah satu aturan validasi status untuk tenant test. */
    private function aturan(string $status, string $aturan, bool $aktif, string $keparahan): void
    {
        DB::table('aset_m_validasi_status_work_order')->updateOrInsert(
            ['tenant_id' => $this->tenantId, 'status' => $status, 'aturan' => $aturan],
            ['id' => (string) Str::ulid(), 'aktif' => $aktif, 'keparahan' => $keparahan, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true,
            ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
