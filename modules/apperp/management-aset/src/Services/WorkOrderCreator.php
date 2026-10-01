<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeJenisAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\master\Trade;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;
use stdClass;

/**
 * Satu-satunya jalur pembuatan work order pemeliharaan.
 *
 * Dipakai layar work order, usulan jadwal pemeliharaan, dan permintaan pemeliharaan. Ketiganya
 * melewati pemeriksaan yang sama — master aktif, varian milik jenis pekerjaannya, jenis pekerjaan
 * cocok dengan jenis aset, aset dalam jangkauan organisasi dan masih beredar — lalu menerima nomor
 * dari Number Sequence dan checklist bawaan jenis pekerjaannya. Dipindahkan dari controller work
 * order supaya jalur kedua dan ketiga tidak menyalin aturan yang lambat laun menyimpang.
 */
final class WorkOrderCreator
{
    private const RESOURCE = 'pemeliharaan-aset';

    public function __construct(private readonly AssetNumberSequenceIssuer $numbers) {}

    /**
     * Dokumen yang pernah dibuat dengan kunci yang sama.
     *
     * `withTrashed` karena replay idempoten harus tetap menemukan dokumen yang sudah diarsipkan;
     * tanpa itu permintaan ulang mencoba menyisipkan baris kembar.
     */
    public function replay(string $key): ?stdClass
    {
        return PemeliharaanAset::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    /**
     * Membuat satu work order draf dari payload berbentuk `POST pemeliharaan-aset`.
     *
     * @param  array<string, mixed>  $data
     * @return array{record: stdClass, replayed: bool}
     *
     * @throws NumberSequenceException
     */
    public function create(Request $request, array $data, string $key): array
    {
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $locations = $this->validateLookups($request, $data);

        // Nomor diterbitkan setelah seluruh validasi supaya permintaan yang ditolak tidak pernah
        // membakar counter.
        $kode = $this->numbers->issue('management-aset.'.self::RESOURCE, $this->tenant($request), self::RESOURCE.':'.$key, $data['legal_entity_id']);

        try {
            DB::transaction(function () use ($request, $data, $key, $kode, $locations): void {
                $record = $this->header($request, $data, $key, $kode);
                (new PemeliharaanAset)->forceFill($record)->save();
                $this->replaceJobLines($record['id'], $data['details'], $locations);
            });
        } catch (QueryException $exception) {
            $existing = $this->replay($key);
            if (! $existing) {
                throw $exception;
            }

            return ['record' => $existing, 'replayed' => true];
        }

        // Dibaca ulang supaya `version` yang dipulangkan adalah nilai di database.
        return ['record' => $this->replay($key) ?? throw new \LogicException('Work order yang baru dibuat tidak ditemukan.'), 'replayed' => false];
    }

    /**
     * Seluruh master dan aset yang dirujuk wajib aktif dan berada dalam jangkauan organisasi
     * pengguna. Mengembalikan lokasi aset saat ini untuk disalin ke baris pekerjaan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    public function validateLookups(Request $request, array $data): array
    {
        $this->requireActiveMaster(TipeWorkOrder::class, [$data['tipe_work_order_id']], 'tipe_work_order_id', 'Tipe work order tidak ditemukan atau sudah tidak aktif.');
        if ($data['tingkat_layanan_id'] ?? null) {
            $this->requireActiveMaster(TingkatLayanan::class, [$data['tingkat_layanan_id']], 'tingkat_layanan_id', 'Tingkat layanan tidak ditemukan atau sudah tidak aktif.');
        }

        $details = $data['details'];
        $this->requireActiveMaster(MaintenanceJobType::class, $this->idsOf($details, 'maintenance_job_type_id'), 'details', 'Jenis pekerjaan maintenance tidak ditemukan atau sudah tidak aktif.');
        $this->requireActiveMaster(Trade::class, $this->idsOf($details, 'trade_id'), 'details', 'Bidang keahlian tidak ditemukan atau sudah tidak aktif.');

        // Varian harus milik jenis pekerjaan pada baris yang sama; varian dari job type lain akan
        // lolos pemeriksaan keberadaan biasa dan diam-diam salah pasang.
        foreach ($details as $detail) {
            if (($detail['variant_id'] ?? null) !== null) {
                $matches = MaintenanceJobTypeVariant::query()
                    ->where([
                        'id' => $detail['variant_id'],
                        'maintenance_job_type_id' => $detail['maintenance_job_type_id'],
                        'aktif' => true,
                    ])->exists();
                if (! $matches) {
                    throw ValidationException::withMessages(['details' => 'Varian pekerjaan harus berasal dari jenis pekerjaan yang dipilih pada baris yang sama.']);
                }
            }

            $jobTypeHasLinks = MaintenanceJobTypeJenisAset::query()
                ->where('job_type_id', $detail['maintenance_job_type_id'])
                ->exists();
            if (! $jobTypeHasLinks) {
                continue;
            }

            $allowed = MaintenanceJobTypeJenisAset::query()
                ->join('aset_tr_aset as aset', function ($join): void {
                    $join->on('aset.jenis_aset_id', '=', 'aset_m_maintenance_job_type_jenis_aset.jenis_aset_id')
                        ->on('aset.tenant_id', '=', 'aset_m_maintenance_job_type_jenis_aset.tenant_id');
                })
                ->where([
                    'aset_m_maintenance_job_type_jenis_aset.job_type_id' => $detail['maintenance_job_type_id'],
                    // `withTrashed`: aset yang sudah diarsipkan tetap ditolak, tetapi oleh
                    // pemeriksaan jangkauan organisasi di bawah, dengan pesannya sendiri.
                    'aset_m_maintenance_job_type_jenis_aset.jenis_aset_id' => Aset::withTrashed()
                        ->where('id', $detail['aset_id'])
                        ->value('jenis_aset_id'),
                    'aset.id' => $detail['aset_id'],
                ])->exists();
            if (! $allowed) {
                throw ValidationException::withMessages(['details' => 'Jenis pekerjaan tidak tersedia untuk jenis aset yang dipilih. Atur relasi jenis aset pada master maintenance terlebih dahulu.']);
            }
        }

        return $this->lokasiAset($request, $this->idsOf($details, 'aset_id'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function header(Request $request, array $data, string $key, string $kode, bool $new = true): array
    {
        return array_filter([
            'id' => $new ? (string) Str::ulid() : null,
            'tenant_id' => $new ? $this->tenant($request) : null,
            'creation_key' => $new ? $key : null,
            'kode' => $new ? $kode : null,
            'legal_entity_id' => $data['legal_entity_id'],
            'responsible_org_unit_id' => $data['responsible_org_unit_id'],
            'tipe_work_order_id' => $data['tipe_work_order_id'],
            'tingkat_layanan_id' => $data['tingkat_layanan_id'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
            'penanggung_jawab_user_id' => $data['penanggung_jawab_user_id'] ?? null,
            'diharapkan_mulai' => $data['diharapkan_mulai'] ?? null,
            'diharapkan_selesai' => $data['diharapkan_selesai'] ?? null,
            'dijadwalkan_mulai' => $data['dijadwalkan_mulai'] ?? null,
            'dijadwalkan_selesai' => $data['dijadwalkan_selesai'] ?? null,
            'status' => $new ? WorkOrderStatus::DRAFT : null,
            'created_at' => $new ? now() : null,
            'updated_at' => now(),
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @param  array<string, ?string>  $locations
     */
    public function replaceJobLines(string $workOrderId, array $details, array $locations): void
    {
        $rows = collect($details)->values()->map(fn (array $detail, int $index): PemeliharaanAsetDetail => PemeliharaanAsetDetail::create([
            'pemeliharaan_aset_id' => $workOrderId,
            'line_number' => $index + 1,
            'aset_id' => $detail['aset_id'],
            // Lokasi disalin, bukan dirujuk lewat aset: aset boleh berpindah setelah pekerjaan
            // selesai dan riwayat harus tetap menunjuk tempat pengerjaan.
            'lokasi_aset_id' => $locations[$detail['aset_id']] ?? null,
            'maintenance_job_type_id' => $detail['maintenance_job_type_id'],
            'variant_id' => $detail['variant_id'] ?? null,
            'trade_id' => $detail['trade_id'] ?? null,
            'ditugaskan_ke_user_id' => $detail['ditugaskan_ke_user_id'] ?? null,
            'dijadwalkan_mulai' => $detail['dijadwalkan_mulai'] ?? null,
            'dijadwalkan_selesai' => $detail['dijadwalkan_selesai'] ?? null,
            'estimasi_jam' => $detail['estimasi_jam'] ?? null,
            'catatan' => $detail['catatan'] ?? null,
        ]));

        $snapshot = app(MaintenanceChecklistSnapshot::class);
        foreach ($rows as $row) {
            $snapshot->applyDefault($row);
        }
    }

    /**
     * Aset dibaca lewat jangkauan organisasi, bukan sekadar tenant: pengguna tidak boleh membuat
     * pekerjaan atas aset milik unit yang tidak dapat ia lihat.
     *
     * @param  list<string>  $ids
     * @return array<string, ?string>
     */
    private function lokasiAset(Request $request, array $ids): array
    {
        $query = Aset::query()->whereIn('id', $ids);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $daftarAset = $query->toBase()->get(['id', 'kode', 'lokasi_aset_id', 'lifecycle_state']);
        if ($daftarAset->count() !== count($ids)) {
            throw ValidationException::withMessages(['details' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }

        // Aset yang sudah dihentikan atau dilepas tidak boleh menerima pekerjaan baru: padanan
        // penanda **Active** pada `Asset lifecycle state` di Dynamics 365 Asset Management.
        foreach ($daftarAset as $aset) {
            if (! StatusAset::bolehDibuatkanWorkOrder($aset->lifecycle_state)) {
                throw ValidationException::withMessages([
                    'details' => 'Aset '.$aset->kode.' sudah dihentikan penggunaannya atau sudah dilepas, sehingga tidak dapat dibuatkan work order.',
                ]);
            }
        }

        return $daftarAset->pluck('lokasi_aset_id', 'id')->all();
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $ids
     */
    private function requireActiveMaster(string $model, array $ids, string $field, string $message): void
    {
        if ($ids === []) {
            return;
        }
        $found = $model::query()->whereIn('id', $ids)->where('aktif', true)->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return list<string>
     */
    private function idsOf(array $details, string $column): array
    {
        return array_values(array_unique(array_filter(array_column($details, $column))));
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
