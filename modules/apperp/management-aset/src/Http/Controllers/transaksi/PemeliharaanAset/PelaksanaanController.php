<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PemeliharaanAset;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplateLine;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistVariableValue;
use Modules\Apperp\ManagementAset\Models\master\SebabKerusakan;
use Modules\Apperp\ManagementAset\Models\master\TindakanPerbaikan;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\master\ValidasiStatusWorkOrder;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetChecklist;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetStatusLog;
use Modules\Apperp\ManagementAset\Services\MaintenanceChecklistSnapshot;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;
use Modules\Apperp\ManagementAset\Support\WorkOrderValidation;

/**
 * Pelaksanaan work order: perpindahan status, pengisian checklist, dan daftar pekerjaan
 * milik satu teknisi.
 *
 * Dipisahkan dari controller dokumen supaya penyuntingan di kantor dan pengerjaan di
 * lapangan tidak berbagi jalur, izin, maupun aturan. Teknisi mengetuk tiga hal — mulai,
 * isi, selesai — dan tidak pernah menyentuh bentuk dokumennya.
 *
 * Penyaringan tenant datang dari scope model; yang masih menyebut `tenant_id` di sini
 * hanyalah tabel yang di-`join`, yang memang tidak ikut tersaring.
 */
class PelaksanaanController extends Controller
{
    private const RESOURCE = 'pemeliharaan-aset';

    private const TABEL_BARIS = 'aset_tr_pemeliharaan_aset_details';

    private const TABEL_NILAI = 'aset_m_maintenance_checklist_variable_value';

    private const TABEL_BARIS_TEMPLATE = 'aset_m_maintenance_checklist_template_line';

    /**
     * Daftar pekerjaan milik pengguna yang sedang masuk.
     *
     * Ia menyaring pada baris, bukan pada header, karena penugasan melekat pada baris:
     * satu work order dapat dikerjakan beberapa orang untuk aset yang berbeda.
     */
    public function pekerjaanSaya(Request $request): JsonResponse
    {
        $this->guard($request, 'execute');
        $userId = (string) $request->attributes->get('coreerp.user_id');

        $query = PemeliharaanAsetDetail::query()
            ->join('aset_tr_pemeliharaan_aset as wo', function ($join): void {
                $join->on('wo.id', '=', self::TABEL_BARIS.'.pemeliharaan_aset_id')->on('wo.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_tr_penerimaan_aset as aset', function ($join): void {
                $join->on('aset.id', '=', self::TABEL_BARIS.'.asset_id')->on('aset.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_maintenance_job_type as pekerjaan', function ($join): void {
                $join->on('pekerjaan.id', '=', self::TABEL_BARIS.'.maintenance_job_type_id')->on('pekerjaan.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_lokasi_aset as lokasi', function ($join): void {
                $join->on('lokasi.id', '=', self::TABEL_BARIS.'.asset_location_id')->on('lokasi.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->where(self::TABEL_BARIS.'.ditugaskan_ke_user_id', $userId)
            ->whereNull('wo.deleted_at')
            // Draf belum dijanjikan kepada siapa pun dan yang sudah selesai tidak lagi
            // menunggu tindakan; keduanya hanya akan menjadi kebisingan di layar lapangan.
            ->whereIn('wo.status', [WorkOrderStatus::DIJADWALKAN, WorkOrderStatus::DIKERJAKAN]);
        app(OrganizationScope::class)->query($query, $request, 'wo.legal_entity_id', 'wo.responsible_org_unit_id');

        return response()->json(['data' => $query
            ->orderByRaw('coalesce('.self::TABEL_BARIS.'.dijadwalkan_mulai, wo.dijadwalkan_mulai) asc')
            ->toBase()
            ->get([
                self::TABEL_BARIS.'.id', self::TABEL_BARIS.'.pemeliharaan_aset_id', self::TABEL_BARIS.'.line_number', self::TABEL_BARIS.'.hasil',
                self::TABEL_BARIS.'.dijadwalkan_mulai', self::TABEL_BARIS.'.dijadwalkan_selesai', self::TABEL_BARIS.'.aktual_jam',
                'wo.kode as work_order_kode', 'wo.status', 'wo.keterangan',
                'aset.kode as asset_kode',
                'pekerjaan.nama as job_type_nama',
                'lokasi.nama as lokasi_nama',
            ])]);
    }

    /** Memindahkan status work order dan mencatat perpindahannya. */
    public function pindahStatus(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'ke_status' => ['required', Rule::in(WorkOrderStatus::ALL)],
            'alasan' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $target = $input['ke_status'];

        // Baca sekilas untuk mengetahui status asal, karena aksi permission yang menjaga
        // transisi baru diketahui setelah pasangan asal-tujuan diketahui.
        $current = $this->workOrder($request, $id);
        $aksi = WorkOrderStatus::aksiUntuk($current->status, $target);
        if ($aksi === null) {
            return response()->json(['error' => [
                'code' => 'transisi_tidak_sah',
                'message' => 'Work order berstatus '.$current->status.' tidak dapat berpindah ke '.$target.'.',
            ]], 422);
        }
        $this->guard($request, $aksi);
        app(OrganizationScope::class)->require($request, $current->legal_entity_id, $current->responsible_org_unit_id);
        if (WorkOrderStatus::butuhAlasan($target) && trim((string) ($input['alasan'] ?? '')) === '') {
            throw ValidationException::withMessages(['alasan' => 'Pembatalan harus menyertakan alasan.']);
        }

        $result = DB::transaction(function () use ($request, $id, $target, $input, $current): array {
            // Dikunci ulang di dalam transaksi: antara pembacaan di atas dan penulisan di
            // sini, orang lain dapat memindahkan status yang sama.
            $locked = PemeliharaanAset::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->toBase()->first();
            if (! $locked || $locked->status !== $current->status || (int) $locked->version !== (int) $input['version']) {
                return ['stale' => true];
            }
            $peringatan = $this->pastikanSyaratTerpenuhi($locked, $target);

            $updated = PemeliharaanAset::query()->where('id', $id)->update([
                'status' => $target,
                ...$this->capWaktu($locked, $target),
                'version' => (int) $locked->version + 1,
                'updated_at' => now(),
            ]);
            if ($target === WorkOrderStatus::SELESAI) {
                $this->simpulkanHasil($id);
            }
            PemeliharaanAsetStatusLog::create([
                'pemeliharaan_aset_id' => $id,
                'dari_status' => $locked->status, 'ke_status' => $target,
                'oleh_user_id' => (string) $request->attributes->get('coreerp.user_id'),
                'alasan' => $input['alasan'] ?? null,
                // Peringatan yang dilewati ikut tersimpan; tanpa jejak ini, "lanjut dengan
                // peringatan" tidak dapat dibedakan dari "semuanya lengkap".
                'peringatan' => $peringatan === [] ? null : implode("\n", $peringatan),
            ]);

            return ['stale' => $updated === 0];
        });

        if ($result['stale']) {
            return response()->json(['error' => [
                'code' => 'stale_version',
                'message' => 'Work order telah berubah. Muat ulang lalu coba lagi.',
            ]], 409);
        }

        return response()->json(['data' => $this->workOrder($request, $id)]);
    }

    public function checklist(Request $request, string $id, string $jobId): JsonResponse
    {
        $this->guard($request, 'read');
        $this->jobLine($request, $id, $jobId);

        return response()->json(['data' => $this->barisChecklist($jobId)]);
    }

    /**
     * Menyalin baris template menjadi baris checklist work order.
     *
     * Disalin, bukan dirujuk: template boleh berubah bulan depan tanpa mengubah arti
     * pemeriksaan yang sudah dikerjakan. Baris bertipe `template` dimekarkan menjadi
     * baris-barisnya di tempat, sehingga teknisi melihat satu daftar datar.
     */
    public function salinDariTemplate(Request $request, string $id, string $jobId): JsonResponse
    {
        $this->guard($request, 'update');
        $tenant = $this->tenant($request);
        $workOrder = $this->workOrder($request, $id);
        abort_unless(
            in_array($workOrder->status, [WorkOrderStatus::DRAFT, WorkOrderStatus::DIJADWALKAN], true),
            422,
            'Checklist hanya dapat disusun sebelum pekerjaan dimulai.',
        );
        app(OrganizationScope::class)->require($request, $workOrder->legal_entity_id, $workOrder->responsible_org_unit_id);
        $this->jobLine($request, $id, $jobId);

        $templateId = $request->validate([
            'template_id' => ['required', 'ulid', Rule::exists('aset_m_maintenance_checklist_template', 'id')
                ->where('tenant_id', $tenant)->whereNull('deleted_at')],
        ])['template_id'];

        $snapshot = app(MaintenanceChecklistSnapshot::class);
        if ($snapshot->copyTemplate($jobId, $templateId) === 0) {
            throw ValidationException::withMessages(['template_id' => 'Template checklist ini belum memiliki baris pemeriksaan.']);
        }

        return response()->json(['data' => $this->barisChecklist($jobId)], 201);
    }

    /**
     * Menyimpan hasil pemeriksaan satu baris pekerjaan sekaligus.
     *
     * Hanya nilai yang dapat diubah teknisi. Nama, tipe, satuan, dan penanda wajib adalah
     * salinan prosedur; membiarkannya disunting dari lapangan berarti membiarkan orang
     * mengubah pertanyaan setelah melihat jawabannya.
     */
    public function simpanChecklist(Request $request, string $id, string $jobId): JsonResponse
    {
        $this->guard($request, 'execute');
        $workOrder = $this->workOrder($request, $id);
        abort_unless(
            $workOrder->status === WorkOrderStatus::DIKERJAKAN,
            422,
            'Checklist hanya dapat diisi ketika pekerjaan sedang dikerjakan.',
        );
        app(OrganizationScope::class)->require($request, $workOrder->legal_entity_id, $workOrder->responsible_org_unit_id);
        $this->jobLine($request, $id, $jobId);

        $data = $request->validate([
            'baris' => ['present', 'array', 'max:200'],
            'baris.*.id' => ['required', 'ulid'],
            'baris.*.nilai' => ['nullable', 'string', 'max:255'],
            'baris.*.tidak_berlaku' => ['sometimes', 'boolean'],
            'baris.*.catatan_teknisi' => ['nullable', 'string', 'max:2000'],
        ]);

        $existing = $this->barisChecklist($jobId)->keyBy('id');
        $userId = (string) $request->attributes->get('coreerp.user_id');

        DB::transaction(function () use ($data, $existing, $userId): void {
            foreach ($data['baris'] as $baris) {
                $row = $existing->get($baris['id']);
                if (! $row) {
                    throw ValidationException::withMessages(['baris' => 'Ada baris checklist yang bukan milik pekerjaan ini.']);
                }
                $tidakBerlaku = filter_var($baris['tidak_berlaku'] ?? false, FILTER_VALIDATE_BOOL);
                $nilai = $tidakBerlaku ? null : ($baris['nilai'] ?? null);
                $this->pastikanNilaiSah($row, $nilai);
                $resultCode = $this->resultCode($row, $nilai);
                $catatan = trim((string) ($baris['catatan_teknisi'] ?? ''));
                if ($resultCode === 'none' && $catatan === '') {
                    throw ValidationException::withMessages(['baris' => 'Pilih alasan di catatan teknisi saat hasil pemeriksaan Tidak dinilai.']);
                }

                PemeliharaanAsetChecklist::query()->where('id', $row->id)->update([
                    'nilai' => $nilai,
                    'result_code' => $resultCode,
                    'tidak_berlaku' => $tidakBerlaku,
                    'catatan_teknisi' => $catatan === '' ? null : $catatan,
                    'diperiksa' => $tidakBerlaku || ($nilai !== null && $nilai !== ''),
                    'diperiksa_oleh_user_id' => $userId,
                    'diperiksa_pada' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['data' => $this->barisChecklist($jobId)]);
    }

    public function simpanPelaksanaan(Request $request, string $id, string $jobId): JsonResponse
    {
        $this->guard($request, 'execute');
        $tenant = $this->tenant($request);
        $workOrder = $this->workOrder($request, $id);
        abort_unless(
            in_array($workOrder->status, [WorkOrderStatus::DIKERJAKAN, WorkOrderStatus::SELESAI], true),
            422,
            'Hasil pelaksanaan hanya dapat diubah saat pekerjaan sedang dikerjakan atau sudah selesai.',
        );
        app(OrganizationScope::class)->require($request, $workOrder->legal_entity_id, $workOrder->responsible_org_unit_id);
        $this->jobLine($request, $id, $jobId);

        $data = $request->validate([
            'aktual_jam' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'sebab_kerusakan_id' => ['nullable', 'ulid', Rule::exists('aset_m_sebab_kerusakan', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'tindakan_perbaikan_id' => ['nullable', 'ulid', Rule::exists('aset_m_tindakan_perbaikan', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'sebab_kerusakan_keterangan' => ['nullable', 'string', 'max:1000'],
            'tindakan_perbaikan_keterangan' => ['nullable', 'string', 'max:1000'],
        ]);

        $sebabKeterangan = $this->keteranganPilihan(SebabKerusakan::class, $data['sebab_kerusakan_id'] ?? null, $data['sebab_kerusakan_keterangan'] ?? null, 'sebab_kerusakan_keterangan');
        $tindakanKeterangan = $this->keteranganPilihan(TindakanPerbaikan::class, $data['tindakan_perbaikan_id'] ?? null, $data['tindakan_perbaikan_keterangan'] ?? null, 'tindakan_perbaikan_keterangan');

        PemeliharaanAsetDetail::query()->where([
            'id' => $jobId,
            'pemeliharaan_aset_id' => $id,
        ])->update([
            'aktual_jam' => $data['aktual_jam'] ?? null,
            'sebab_kerusakan_id' => $data['sebab_kerusakan_id'] ?? null,
            'tindakan_perbaikan_id' => $data['tindakan_perbaikan_id'] ?? null,
            'sebab_kerusakan_keterangan' => $sebabKeterangan,
            'tindakan_perbaikan_keterangan' => $tindakanKeterangan,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->workOrder($request, $id)]);
    }

    /** @param class-string<Model> $model */
    private function keteranganPilihan(string $model, ?string $id, ?string $value, string $field): ?string
    {
        if ($id === null || ! $model::query()->where(['id' => $id, 'minta_keterangan' => true])->exists()) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            throw ValidationException::withMessages([$field => 'Isi keterangan untuk pilihan ini.']);
        }

        return $value;
    }

    /**
     * Syarat sebelum status boleh berpindah. Inilah yang membuat kualitas data tidak
     * bergantung pada kedisiplinan teknisi.
     *
     * Dua lapis. Pertama syarat bentuk dokumen yang selalu berlaku dan tidak masuk akal
     * dimatikan — work order tanpa baris pekerjaan tidak dapat dijadwalkan, apa pun
     * pengaturan tenant. Kedua aturan isi data yang dapat diatur per status berikut tingkat
     * keparahannya.
     *
     * @return list<string> peringatan yang dilewati; kosong bila tidak ada
     */
    private function pastikanSyaratTerpenuhi(object $workOrder, string $target): array
    {
        $jobs = PemeliharaanAsetDetail::query()
            ->where('pemeliharaan_aset_id', $workOrder->id)->toBase()->get();

        if ($target === WorkOrderStatus::DIJADWALKAN) {
            if ($jobs->isEmpty()) {
                throw ValidationException::withMessages(['ke_status' => 'Work order harus memiliki minimal satu baris pekerjaan sebelum dijadwalkan.']);
            }
            if ($workOrder->dijadwalkan_mulai === null) {
                throw ValidationException::withMessages(['ke_status' => 'Tanggal mulai terjadwal harus diisi sebelum work order dijadwalkan.']);
            }
            // `withTrashed`: aturan satu pelaksana tetap berlaku walau tipe work order-nya
            // sudah diarsipkan setelah dokumen ini dibuat.
            $tipe = TipeWorkOrder::withTrashed()->where('id', $workOrder->tipe_work_order_id)->toBase()->first();
            if ($tipe && $tipe->satu_pekerja) {
                $pelaksana = $jobs->pluck('ditugaskan_ke_user_id')->unique();
                if ($pelaksana->count() !== 1 || $pelaksana->first() === null) {
                    throw ValidationException::withMessages(['ke_status' => 'Tipe work order ini hanya mengizinkan satu pelaksana untuk seluruh baris pekerjaan.']);
                }
            }
        }

        return $this->terapkanAturanValidasi($target, $jobs);
    }

    /**
     * Menjalankan aturan validasi milik status tujuan.
     *
     * Aturan yang gagal dikumpulkan dahulu, baru diputuskan. Menolak pada pelanggaran
     * pertama membuat pengguna memperbaiki satu hal, mencoba lagi, lalu ditolak lagi karena
     * hal berikutnya; semua kekurangan sebaiknya disebut sekaligus.
     *
     * @param  Collection<int, object>  $jobs
     * @return list<string>
     */
    private function terapkanAturanValidasi(string $target, Collection $jobs): array
    {
        $aturan = ValidasiStatusWorkOrder::query()
            ->where(['status' => $target, 'aktif' => true])->toBase()->get();
        if ($aturan->isEmpty()) {
            return [];
        }

        $errors = [];
        $peringatan = [];
        foreach ($aturan as $baris) {
            $jumlah = $this->hitungPelanggaran($baris->aturan, $jobs);
            if ($jumlah === 0) {
                continue;
            }
            $pesan = WorkOrderValidation::pesan($baris->aturan, $jumlah);
            match ($baris->keparahan) {
                WorkOrderValidation::ERROR => $errors[] = $pesan,
                WorkOrderValidation::PERINGATAN => $peringatan[] = $pesan,
                default => null,
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['ke_status' => $errors]);
        }

        return $peringatan;
    }

    /** @param Collection<int, object> $jobs */
    private function hitungPelanggaran(string $aturan, Collection $jobs): int
    {
        return match ($aturan) {
            WorkOrderValidation::CHECKLIST => PemeliharaanAsetChecklist::query()
                ->whereIn('pemeliharaan_aset_detail_id', $jobs->pluck('id'))
                ->where('wajib', true)
                ->where('tidak_berlaku', false)
                ->where(fn ($query) => $query->whereNull('nilai')->orWhere('nilai', ''))
                ->count(),
            WorkOrderValidation::SEBAB => $jobs->whereNull('sebab_kerusakan_id')->count(),
            WorkOrderValidation::TINDAKAN => $jobs->whereNull('tindakan_perbaikan_id')->count(),
            default => 0,
        };
    }

    /**
     * Menyimpulkan hasil tiap baris pekerjaan dari hasil pemeriksaannya.
     *
     * Diturunkan, bukan diketik: kalau teknisi boleh menyatakan "lulus" sementara salah satu
     * pemeriksaannya berhasil `fail` atau belum dapat dinilai, kesimpulan itu tidak dapat
     * dipercaya. Baris tanpa checklist dibiarkan kosong karena memang tidak ada yang dapat
     * disimpulkan darinya.
     */
    private function simpulkanHasil(string $workOrderId): void
    {
        $jobs = PemeliharaanAsetDetail::query()
            ->where('pemeliharaan_aset_id', $workOrderId)->pluck('id');

        foreach ($jobs as $jobId) {
            $baris = PemeliharaanAsetChecklist::query()
                ->where('pemeliharaan_aset_detail_id', $jobId)
                ->where('tipe', '!=', 'header')
                ->toBase()
                ->get(['result_code', 'tidak_berlaku']);
            if ($baris->isEmpty()) {
                continue;
            }

            $berlaku = $baris->where('tidak_berlaku', false);
            $hasil = match (true) {
                $berlaku->contains(fn (object $row): bool => $row->result_code === 'fail') => 'gagal',
                $berlaku->isEmpty() => 'tidak_berlaku',
                $berlaku->contains(fn (object $row): bool => $row->result_code === 'none') => 'tidak_dinilai',
                default => 'lulus',
            };
            PemeliharaanAsetDetail::query()
                ->where('id', $jobId)->update(['hasil' => $hasil, 'updated_at' => now()]);
        }
    }

    /** @return array<string, mixed> */
    private function capWaktu(object $workOrder, string $target): array
    {
        if ($target === WorkOrderStatus::DIKERJAKAN && $workOrder->aktual_mulai === null) {
            return ['aktual_mulai' => now()];
        }
        if ($target === WorkOrderStatus::SELESAI && $workOrder->aktual_selesai === null) {
            return ['aktual_selesai' => now()];
        }

        return [];
    }

    /** Nilai variabel harus berasal dari pilihannya; pengukuran harus berupa angka. */
    private function pastikanNilaiSah(object $row, ?string $nilai): void
    {
        if ($nilai === null || $nilai === '') {
            return;
        }
        if ($row->tipe === 'measurement' && ! is_numeric($nilai)) {
            throw ValidationException::withMessages(['baris' => 'Nilai untuk pemeriksaan "'.$row->nama.'" harus berupa angka.']);
        }
        if ($row->tipe !== 'variable') {
            return;
        }
        $sah = $this->pilihanVariabel($row->sumber_id, $nilai)->exists();
        if (! $sah) {
            throw ValidationException::withMessages(['baris' => 'Nilai "'.$nilai.'" bukan pilihan yang sah untuk pemeriksaan "'.$row->nama.'".']);
        }
    }

    /** Hasil pilihan melekat pada variabel; rentang pengukuran mengevaluasi angka langsung. */
    private function resultCode(object $row, ?string $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }
        if ($row->tipe === 'measurement') {
            if ($row->min_value === null || $row->max_value === null) {
                return null;
            }

            return (float) $nilai < (float) $row->min_value || (float) $nilai > (float) $row->max_value ? 'fail' : 'pass';
        }
        if ($row->tipe !== 'variable') {
            return null;
        }

        return $this->pilihanVariabel($row->sumber_id, $nilai)->value(self::TABEL_NILAI.'.result_code');
    }

    /**
     * Satu pilihan nilai yang dirujuk sebuah baris template.
     *
     * Baris template diikat lewat `join`, jadi ia membawa penyaringan tenant sendiri:
     * scope hanya menyaring tabel pilihan nilai yang menjadi induk query.
     */
    private function pilihanVariabel(?string $sumberId, string $nilai): mixed
    {
        return MaintenanceChecklistVariableValue::query()
            ->join(self::TABEL_BARIS_TEMPLATE.' as baris', function ($join): void {
                $join->on('baris.variable_id', '=', self::TABEL_NILAI.'.variable_id')
                    ->on('baris.tenant_id', '=', self::TABEL_NILAI.'.tenant_id');
            })
            ->where(['baris.id' => $sumberId, self::TABEL_NILAI.'.value' => $nilai]);
    }

    /** @return Collection<int, object> */
    private function barisChecklist(string $jobId): Collection
    {
        $rows = PemeliharaanAsetChecklist::query()
            ->where('pemeliharaan_aset_detail_id', $jobId)
            ->orderBy('line_number')->toBase()->get();

        $sourceIds = $rows->pluck('sumber_id')->filter()->values();
        $variables = MaintenanceChecklistTemplateLine::query()
            ->join(self::TABEL_NILAI.' as nilai', function ($join): void {
                $join->on('nilai.variable_id', '=', self::TABEL_BARIS_TEMPLATE.'.variable_id')
                    ->on('nilai.tenant_id', '=', self::TABEL_BARIS_TEMPLATE.'.tenant_id');
            })
            ->whereIn(self::TABEL_BARIS_TEMPLATE.'.id', $sourceIds)
            ->orderBy('nilai.line_number')
            ->toBase()
            ->get([self::TABEL_BARIS_TEMPLATE.'.id as source_id', 'nilai.value', 'nilai.result_code'])
            ->groupBy('source_id');

        return $rows->map(function (object $row) use ($variables): object {
            $row->pilihan = $variables->get($row->sumber_id, collect())->values();

            return $row;
        });
    }

    private function jobLine(Request $request, string $workOrderId, string $jobId): object
    {
        $this->workOrder($request, $workOrderId);

        return PemeliharaanAsetDetail::query()->where([
            'id' => $jobId, 'pemeliharaan_aset_id' => $workOrderId,
        ])->toBase()->firstOrFail();
    }

    private function workOrder(Request $request, string $id): object
    {
        $query = PemeliharaanAset::query()->where('id', $id);
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');

        return $query->toBase()->firstOrFail();
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(
            in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true),
            403,
        );
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
