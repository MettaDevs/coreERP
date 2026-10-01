<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\TenantRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Services\ProvisionIndonesiaStarterData;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class MaintenanceSetupTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->unitId = $this->buatSatuanUji($this->tenantId, 'V', 'Volt');
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/units-of-measure/resolve')) {
                return Http::response(['data' => [[
                    'id' => $this->unitId, 'code' => 'V', 'name' => 'Volt', 'symbol' => 'V', 'decimal_places' => 2,
                ]]]);
            }
            static $number = 0;
            $number++;

            return Http::response(['data' => ['number' => 'MNT'.str_pad((string) $number, 5, '0', STR_PAD_LEFT)]]);
        });
    }

    public function test_setup_maintenance_menyimpan_job_type_varian_relasi_dan_checklist(): void
    {
        $jobType = $this->postMaster('maintenance-job-types', ['nama' => 'Inspeksi', 'category_code' => 'preventive'])->assertCreated()->json('data.id');
        $variant = $this->postMaster('maintenance-job-type-variants', ['nama' => 'Mingguan', 'maintenance_job_type_id' => $jobType])->assertCreated()->json('data.id');

        $jenisAset = $this->postMaster('jenis-aset', ['nama' => 'Genset'])->assertCreated()->json('data.id');
        $this->withContext(['management-aset.jenis-aset.read', 'management-aset.jenis-aset.update', 'management-aset.maintenance-job-types.read'])
            ->putJson('/api/modules/management-aset/v1/jenis-aset/'.$jenisAset.'/maintenance-job-types', ['jenis_aset_ids' => [$jobType], 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.selected.0.id', $jobType);

        $variable = $this->postMaster('maintenance-checklist-variables', ['nama' => 'Kualitas oli'])->assertCreated()->json('data.id');
        $this->withContext(['management-aset.maintenance-checklist-variables.read', 'management-aset.maintenance-checklist-variables.update'])
            ->putJson('/api/modules/management-aset/v1/maintenance-checklist-variables/'.$variable.'/values', ['version' => 1, 'values' => [
                ['line_number' => 1, 'value' => 'Jernih', 'result_code' => 'pass'],
                ['line_number' => 2, 'value' => 'Keruh', 'result_code' => 'fail'],
                ['line_number' => 3, 'value' => 'Belum dapat diperiksa', 'result_code' => 'none'],
            ]])->assertOk()->assertJsonCount(3, 'data');

        $template = $this->postMaster('maintenance-checklist-templates', ['nama' => 'Pemeriksaan genset'])->assertCreated()->json('data.id');
        $this->withContext(['management-aset.maintenance-checklist-templates.read', 'management-aset.maintenance-checklist-templates.update'])
            ->putJson('/api/modules/management-aset/v1/maintenance-checklist-templates/'.$template.'/lines', ['version' => 1, 'lines' => [
                ['line_number' => 1, 'type' => 'header', 'nama' => 'Pemeriksaan genset', 'wajib' => true],
                ['line_number' => 2, 'type' => 'measurement', 'nama' => 'Tegangan', 'unit_id' => $this->unitId, 'min_value' => 210, 'max_value' => 230, 'wajib' => true, 'instruksi' => 'Ukur pada terminal utama.'],
            ]])->assertOk()->assertJsonCount(2, 'data');

        // Instruksi dan penanda wajib menentukan apa yang dibaca dan harus diisi teknisi,
        // jadi hilangnya keduanya saat menyimpan tidak boleh lolos diam-diam.
        $this->assertDatabaseHas('aset_m_maintenance_checklist_template_line', [
            'template_id' => $template, 'line_number' => 2,
            'instruksi' => 'Ukur pada terminal utama.', 'wajib' => true, 'unit_id' => $this->unitId,
            'unit' => 'V', 'min_value' => 210, 'max_value' => 230,
        ]);
        // Baris judul tidak pernah diisi teknisi, jadi ia tidak boleh menahan penyelesaian
        // walaupun penyusun template menandainya wajib.
        $this->assertDatabaseHas('aset_m_maintenance_checklist_template_line', [
            'template_id' => $template, 'line_number' => 1, 'wajib' => false,
        ]);

        $this->assertDatabaseHas('aset_m_maintenance_job_type_variant', ['id' => $variant, 'maintenance_job_type_id' => $jobType]);
        $this->assertDatabaseHas('aset_m_maintenance_job_type_jenis_aset', ['job_type_id' => $jobType, 'jenis_aset_id' => $jenisAset]);
    }

    public function test_baris_pengukuran_boleh_disimpan_tanpa_satuan(): void
    {
        $template = $this->postMaster('maintenance-checklist-templates', ['nama' => 'Pemeriksaan tanpa satuan'])->assertCreated()->json('data.id');

        $this->withContext(['management-aset.maintenance-checklist-templates.read', 'management-aset.maintenance-checklist-templates.update'])
            ->putJson('/api/modules/management-aset/v1/maintenance-checklist-templates/'.$template.'/lines', ['version' => 1, 'lines' => [
                ['line_number' => 1, 'type' => 'measurement', 'nama' => 'Nilai hasil pemeriksaan', 'min_value' => 1, 'max_value' => 5, 'wajib' => true],
            ]])->assertOk()
            ->assertJsonPath('data.0.unit_id', null)
            ->assertJsonPath('data.0.unit', null);

        $this->assertDatabaseHas('aset_m_maintenance_checklist_template_line', [
            'template_id' => $template, 'line_number' => 1, 'unit_id' => null, 'unit' => null,
            'min_value' => 1, 'max_value' => 5,
        ]);
    }

    public function test_validasi_nama_baris_menunjuk_kolom_dengan_pesan_indonesia(): void
    {
        $template = $this->postMaster('maintenance-checklist-templates', ['nama' => 'Template nama wajib'])->assertCreated()->json('data.id');

        $this->withContext(['management-aset.maintenance-checklist-templates.read', 'management-aset.maintenance-checklist-templates.update'])
            ->putJson('/api/modules/management-aset/v1/maintenance-checklist-templates/'.$template.'/lines', ['lines' => [
                ['line_number' => 1, 'type' => 'text', 'nama' => '', 'wajib' => false],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.nama')
            ->assertJsonFragment(['lines.0.nama' => ['Nama baris wajib diisi.']]);
    }

    /**
     * Baris template disimpan dengan mengklaim versi templatenya: kiriman kedua dari versi yang
     * sama ditolak dan baris kiriman pertama bertahan; kiriman tanpa versi tidak mengubah apa pun.
     */
    public function test_baris_template_dari_versi_basi_atau_tanpa_versi_ditolak(): void
    {
        $template = $this->postMaster('maintenance-checklist-templates', ['nama' => 'Pemeriksaan pompa'])->assertCreated()->json('data.id');
        $pengguna = $this->withContext(['management-aset.maintenance-checklist-templates.read', 'management-aset.maintenance-checklist-templates.update']);
        $alamat = '/api/modules/management-aset/v1/maintenance-checklist-templates/'.$template.'/lines';

        $pengguna->getJson($alamat)->assertOk()->assertJsonPath('version', 1);
        $pengguna->putJson($alamat, ['version' => 1, 'lines' => [['line_number' => 1, 'type' => 'text', 'nama' => 'Periksa seal']]])
            ->assertOk()
            ->assertJsonPath('version', 2);
        $pengguna->putJson($alamat, ['version' => 1, 'lines' => [['line_number' => 1, 'type' => 'text', 'nama' => 'Periksa impeller']]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version');
        $pengguna->putJson($alamat, ['lines' => []])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'version_required');

        $pengguna->getJson($alamat)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama', 'Periksa seal');
    }

    /**
     * Kaitan job type dan jenis aset disunting dari dua arah; tiap arah mengklaim versi pemilik
     * yang disebut alamatnya, dan jawaban bacanya membawa versi itu.
     */
    public function test_kaitan_job_type_dan_jenis_aset_mengklaim_pemilik_pada_alamat(): void
    {
        $jobType = $this->postMaster('maintenance-job-types', ['nama' => 'Kalibrasi'])->assertCreated()->json('data.id');
        $jenisA = $this->postMaster('jenis-aset', ['nama' => 'Timbangan'])->assertCreated()->json('data.id');
        $jenisB = $this->postMaster('jenis-aset', ['nama' => 'Termometer'])->assertCreated()->json('data.id');
        $pengguna = $this->withContext([...$this->permissions('maintenance-job-types'), ...$this->permissions('jenis-aset')]);
        $alamatJobType = '/api/modules/management-aset/v1/maintenance-job-types/'.$jobType.'/jenis-aset';

        $pengguna->getJson($alamatJobType)->assertOk()->assertJsonPath('version', 1);
        $pengguna->putJson($alamatJobType, ['jenis_aset_ids' => [$jenisA], 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $pengguna->putJson($alamatJobType, ['jenis_aset_ids' => [$jenisB], 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version');
        $pengguna->putJson($alamatJobType, ['jenis_aset_ids' => [$jenisB]])->assertStatus(428);
        $this->assertDatabaseHas('aset_m_maintenance_job_type_jenis_aset', ['job_type_id' => $jobType, 'jenis_aset_id' => $jenisA]);
        $this->assertDatabaseMissing('aset_m_maintenance_job_type_jenis_aset', ['job_type_id' => $jobType, 'jenis_aset_id' => $jenisB]);

        // Arah jenis aset memakai versi jenis asetnya sendiri, bukan versi job type.
        $alamatJenis = '/api/modules/management-aset/v1/jenis-aset/'.$jenisB.'/maintenance-job-types';
        $pengguna->getJson($alamatJenis)->assertOk()->assertJsonPath('version', 1);
        $pengguna->putJson($alamatJenis, ['jenis_aset_ids' => [$jobType], 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $pengguna->putJson($alamatJenis, ['jenis_aset_ids' => [], 'version' => 1])->assertStatus(409);
        $this->assertDatabaseHas('aset_m_maintenance_job_type_jenis_aset', ['job_type_id' => $jobType, 'jenis_aset_id' => $jenisB]);

        // Kaitan yang berubah dari satu arah membuat layar arah seberang basi: layar job type yang dibuka
        // pada versi 2 tidak boleh menimpa kaitan Termometer yang baru disimpan dari sisi jenis aset.
        $pengguna->putJson($alamatJobType, ['jenis_aset_ids' => [$jenisA], 'version' => 2])->assertStatus(409);
        $this->assertDatabaseHas('aset_m_maintenance_job_type_jenis_aset', ['job_type_id' => $jobType, 'jenis_aset_id' => $jenisB]);
        $this->assertSame(2, DB::table('aset_m_jenis_aset')->where('id', $jenisA)->value('version'), 'Timbangan dikaitkan dari sisi job type.');
    }

    public function test_aturan_validasi_status_disemai_dan_dapat_diubah_tenant(): void
    {
        $this->semaiMaintenance();

        // Matriks lengkap disemai dan seluruh aturannya aktif secara bawaan.
        $this->assertDatabaseCount('aset_m_validasi_status_work_order', 12);
        $this->assertSame(12, DB::table('aset_m_validasi_status_work_order')->where('aktif', true)->count());
        $this->assertDatabaseHas('aset_m_validasi_status_work_order', [
            'status' => 'selesai', 'aturan' => 'checklist_wajib', 'aktif' => true, 'keparahan' => 'error',
        ]);

        $this->withContext(['management-aset.validasi-status-work-order.read'])
            ->putJson('/api/modules/management-aset/v1/validasi-status-work-order', ['aturan' => []])
            ->assertForbidden();

        $this->withContext(['management-aset.validasi-status-work-order.read', 'management-aset.validasi-status-work-order.update'])
            ->putJson('/api/modules/management-aset/v1/validasi-status-work-order', ['aturan' => [
                ['status' => 'selesai', 'aturan' => 'sebab_kerusakan', 'aktif' => true, 'keparahan' => 'peringatan'],
            ]])
            ->assertOk();

        $this->assertDatabaseHas('aset_m_validasi_status_work_order', [
            'status' => 'selesai', 'aturan' => 'sebab_kerusakan', 'aktif' => true, 'keparahan' => 'peringatan',
        ]);

        // Seed ulang tidak boleh membatalkan keputusan tenant.
        $this->semaiMaintenance();
        $this->assertDatabaseHas('aset_m_validasi_status_work_order', [
            'status' => 'selesai', 'aturan' => 'sebab_kerusakan', 'aktif' => true, 'keparahan' => 'peringatan',
        ]);
    }

    public function test_seed_maintenance_manual_idempotent_dan_tidak_menimpa_data_custom(): void
    {
        $this->semaiMaintenance();
        $this->assertDatabaseCount('aset_m_maintenance_job_type', 7);

        $custom = $this->postMaster('maintenance-job-types', ['nama' => 'Pekerjaan tenant'])->assertCreated()->json('data.id');
        $this->semaiMaintenance();

        $this->assertDatabaseCount('aset_m_maintenance_job_type', 8);
        $this->assertDatabaseHas('aset_m_maintenance_job_type', ['id' => $custom, 'nama' => 'Pekerjaan tenant']);
        $this->assertDatabaseCount('aset_m_maintenance_checklist_variable_value', 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function postMaster(string $resource, array $payload): TestResponse
    {
        return $this->withContext(array_merge($this->permissions($resource), $resource === 'jenis-aset' ? [] : []))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::lower(Str::random(12)))
            ->postJson('/api/modules/management-aset/v1/'.$resource, $this->denganKodeKetik($resource, $payload));
    }

    /** @param list<string> $permissions */
    private function withContext(array $permissions): static
    {
        return $this->sebagaiPengguna($this->tenantId, $permissions);
    }

    /** @return list<string> */
    private function permissions(string $resource): array
    {
        return array_map(fn (string $action): string => 'management-aset.'.$resource.'.'.$action, ['read', 'create', 'update', 'archive']);
    }

    /**
     * Menyemai setup maintenance seperti perintah artisan menyemainya.
     *
     * Lewat `TenantRunner`, bukan panggilan langsung, karena test ini tidak selalu
     * didahului permintaan HTTP — dan hanya permintaan HTTP yang menetapkan tenant aktif.
     * Memanggilnya langsung membuat test lulus atau gagal tergantung apakah kebetulan ada
     * permintaan sebelumnya di metode yang sama, yang bukan perbedaan yang ingin diuji.
     */
    private function semaiMaintenance(): void
    {
        $this->app->make(TenantRunner::class)->runFor(
            $this->tenantId,
            fn (): array => $this->app->make(ProvisionIndonesiaStarterData::class)
                ->maintenanceForTenant($this->tenantId),
        );
    }
}
