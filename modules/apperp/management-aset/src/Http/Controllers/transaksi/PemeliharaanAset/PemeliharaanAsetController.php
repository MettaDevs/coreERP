<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PemeliharaanAset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeAssetType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\master\Trade;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetStatusLog;
use Modules\Apperp\ManagementAset\Services\MaintenanceChecklistSnapshot;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;
use stdClass;

/**
 * Work order pemeliharaan aset.
 *
 * Header memikul dokumen dan jadwalnya; aset justru berada di baris pekerjaan, mengikuti
 * Dynamics 365 F&O, sehingga satu perintah kerja dapat mencakup beberapa aset. Perpindahan
 * status dan pengisian checklist tidak ditangani di sini melainkan di controller
 * pelaksanaan, supaya penyuntingan dokumen dan pengerjaan lapangan tidak berbagi jalur.
 *
 * Penyaringan tenant tidak lagi ditulis di sini: model module membawanya sendiri lewat
 * `MilikTenant`. Yang tersisa hanyalah tabel yang di-`join`, karena tabel yang di-join
 * tidak ikut tersaring scope dan harus membawa `tenant_id`-nya sendiri.
 */
class PemeliharaanAsetController extends Controller
{
    private const RESOURCE = 'pemeliharaan-aset';

    /**
     * Batas kunci idempotensi. Core menolak idempotency_key di atas 160 karakter dan kunci
     * yang dikirim ke sana diawali `pemeliharaan-aset:` (18 karakter), jadi batas app harus
     * 142 supaya permintaan yang sah tidak pernah berubah menjadi 503 di Core.
     */
    private const MAX_CREATION_KEY = 142;

    private const TABEL = 'aset_tr_pemeliharaan_aset';

    private const TABEL_BARIS = 'aset_tr_pemeliharaan_aset_details';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');

        $query = PemeliharaanAset::query();
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');

        return response()->json(['data' => $this->withLookups($query)
            ->orderByDesc(self::TABEL.'.created_at')
            ->get()]);
    }

    public function jobTypesForAsset(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $assetId = $request->validate(['asset_id' => ['required', 'ulid']])['asset_id'];

        $assetQuery = Asset::query()->where('id', $assetId);
        app(OrganizationScope::class)->assetQuery($assetQuery, $request);
        $asset = $assetQuery->toBase()->first(['jenis_aset_id']);
        if (! $asset || $asset->jenis_aset_id === null) {
            return response()->json(['data' => []]);
        }

        $linkedJobTypes = MaintenanceJobTypeAssetType::query()->distinct()->pluck('job_type_id');

        $data = MaintenanceJobType::query()
            ->join('aset_m_maintenance_job_type_asset_type as relasi', function ($join): void {
                $join->on('relasi.job_type_id', '=', 'aset_m_maintenance_job_type.id')
                    ->on('relasi.tenant_id', '=', 'aset_m_maintenance_job_type.tenant_id');
            })
            ->where([
                'aset_m_maintenance_job_type.aktif' => true,
                'relasi.jenis_aset_id' => $asset->jenis_aset_id,
            ])
            ->orderBy('aset_m_maintenance_job_type.kode')
            ->toBase()
            ->get(['aset_m_maintenance_job_type.id', 'aset_m_maintenance_job_type.kode', 'aset_m_maintenance_job_type.nama']);

        // Tenant yang belum mengisi relasi jenis aset tetap dapat membuat work order.
        // Begitu satu job type mulai dikonfigurasi, pilihan untuk job type tersebut
        // mengikuti relasi F&O dan hanya muncul untuk jenis aset yang sesuai.
        if ($linkedJobTypes->isEmpty()) {
            $data = MaintenanceJobType::query()
                ->where('aktif', true)
                ->orderBy('kode')
                ->toBase()
                ->get(['id', 'kode', 'nama']);
        } else {
            $data = $data->merge(
                MaintenanceJobType::query()
                    ->where('aktif', true)
                    ->whereNotIn('id', $linkedJobTypes)
                    ->orderBy('kode')
                    ->toBase()
                    ->get(['id', 'kode', 'nama'])
            )->sortBy('kode')->values();
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $workOrder = $this->workOrder($request, $id);
        $workOrder->details = $this->jobLines($id);
        $workOrder->status_log = PemeliharaanAsetStatusLog::query()
            ->where('pemeliharaan_aset_id', $id)
            ->orderBy('created_at')
            ->toBase()->get();

        return response()->json(['data' => $workOrder]);
    }

    public function store(Request $request, PenerbitNomorAset $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = $this->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $locations = $this->validateLookups($request, $data);

        // Nomor diterbitkan setelah seluruh validasi supaya permintaan yang ditolak tidak
        // pernah membakar counter, dan sebelum transaksi supaya kegagalan Core tidak
        // menahan koneksi database.
        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        try {
            $workOrder = DB::transaction(function () use ($request, $data, $key, $kode, $locations): array {
                $record = $this->header($request, $data, $key, $kode);
                (new PemeliharaanAset)->forceFill($record)->save();
                $this->replaceJobLines($record['id'], $data['details'], $locations);

                return $record;
            });
        } catch (QueryException $exception) {
            $existing = $this->replay($key);
            if (! $existing) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        return response()->json(['data' => $workOrder], 201, [
            'Location' => url('/api/v1/'.self::RESOURCE.'/'.$workOrder['id']),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $workOrder = $this->workOrder($request, $id);
        // Baris pekerjaan memikul hasil checklist, jam aktual, dan penugasan. Sesudah
        // dokumen dijadwalkan, mengganti seluruh baris berarti membuang pekerjaan yang
        // sudah dilakukan orang, jadi penggantian massal hanya sah selama masih draf.
        abort_unless(
            WorkOrderStatus::dapatDisunting($workOrder->status),
            422,
            'Hanya work order draf yang dapat diubah. Batalkan atau kembalikan ke draf lebih dahulu.',
        );

        $data = $this->validated($request);
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        // Dua-duanya diperiksa: unit tempat dokumen berada sekarang dan unit tujuan
        // perubahan. Tanpa yang pertama, dokumen dapat dipindahkan keluar dari unit yang
        // tidak boleh disentuh pengguna; tanpa yang kedua, dipindahkan ke unit asing.
        app(OrganizationScope::class)->require($request, $workOrder->legal_entity_id, $workOrder->responsible_org_unit_id);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $locations = $this->validateLookups($request, $data);

        $changed = DB::transaction(function () use ($request, $id, $version, $data, $locations): int {
            $updated = PemeliharaanAset::query()->where([
                'id' => $id, 'version' => $version,
            ])->update([
                ...$this->header($request, $data, '', '', false),
                'version' => $version + 1,
                'updated_at' => now(),
            ]);
            if ($updated) {
                PemeliharaanAsetDetail::query()->where('pemeliharaan_aset_id', $id)->delete();
                $this->replaceJobLines($id, $data['details'], $locations);
            }

            return $updated;
        });
        if (! $changed) {
            return $this->staleVersion();
        }

        return $this->show($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $workOrder = $this->workOrder($request, $id);
        abort_unless(
            in_array($workOrder->status, [WorkOrderStatus::DRAFT, WorkOrderStatus::DIBATALKAN], true),
            422,
            'Hanya work order draf atau yang sudah dibatalkan yang dapat diarsipkan.',
        );
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        app(OrganizationScope::class)->require($request, $workOrder->legal_entity_id, $workOrder->responsible_org_unit_id);

        $updated = PemeliharaanAset::query()->where([
            'id' => $id, 'version' => $version,
        ])->update(['deleted_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);

        return $updated ? response()->json(status: 204) : $this->staleVersion();
    }

    /**
     * Dokumen yang pernah dibuat dengan kunci yang sama.
     *
     * `withTrashed` karena replay idempoten harus tetap menemukan dokumen yang sudah
     * diarsipkan; tanpa itu permintaan ulang mencoba menyisipkan baris kembar.
     */
    private function replay(string $key): ?stdClass
    {
        return PemeliharaanAset::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['required', 'ulid'],
            'tipe_work_order_id' => ['required', 'ulid'],
            'tingkat_layanan_id' => ['nullable', 'ulid'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'penanggung_jawab_user_id' => ['nullable', 'string', 'max:64'],
            'diharapkan_mulai' => ['nullable', 'date'],
            'diharapkan_selesai' => ['nullable', 'date', 'after_or_equal:diharapkan_mulai'],
            'dijadwalkan_mulai' => ['nullable', 'date'],
            'dijadwalkan_selesai' => ['nullable', 'date', 'after_or_equal:dijadwalkan_mulai'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.asset_id' => ['required', 'ulid'],
            'details.*.maintenance_job_type_id' => ['required', 'ulid'],
            'details.*.variant_id' => ['nullable', 'ulid'],
            'details.*.trade_id' => ['nullable', 'ulid'],
            'details.*.ditugaskan_ke_user_id' => ['nullable', 'string', 'max:64'],
            'details.*.dijadwalkan_mulai' => ['nullable', 'date'],
            'details.*.dijadwalkan_selesai' => ['nullable', 'date', 'after_or_equal:details.*.dijadwalkan_mulai'],
            'details.*.estimasi_jam' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'details.*.catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        // Sebab kerusakan dan tindakan perbaikan sengaja tidak diterima di sini. Keduanya
        // adalah temuan, bukan rencana, dan diisi saat pekerjaan dikerjakan.

        return $data;
    }

    /**
     * Seluruh master dan aset yang dirujuk wajib aktif dan berada dalam jangkauan
     * organisasi pengguna. Mengembalikan lokasi aset saat ini untuk disalin ke baris
     * pekerjaan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    private function validateLookups(Request $request, array $data): array
    {
        $this->requireActiveMaster(TipeWorkOrder::class, [$data['tipe_work_order_id']], 'tipe_work_order_id', 'Tipe work order tidak ditemukan atau sudah tidak aktif.');
        if ($data['tingkat_layanan_id'] ?? null) {
            $this->requireActiveMaster(TingkatLayanan::class, [$data['tingkat_layanan_id']], 'tingkat_layanan_id', 'Tingkat layanan tidak ditemukan atau sudah tidak aktif.');
        }

        $details = $data['details'];
        $this->requireActiveMaster(MaintenanceJobType::class, $this->idsOf($details, 'maintenance_job_type_id'), 'details', 'Jenis pekerjaan maintenance tidak ditemukan atau sudah tidak aktif.');
        $this->requireActiveMaster(Trade::class, $this->idsOf($details, 'trade_id'), 'details', 'Bidang keahlian tidak ditemukan atau sudah tidak aktif.');

        // Varian harus milik jenis pekerjaan pada baris yang sama; varian dari job type lain
        // akan lolos pemeriksaan keberadaan biasa dan diam-diam salah pasang.
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

            $jobTypeHasLinks = MaintenanceJobTypeAssetType::query()
                ->where('job_type_id', $detail['maintenance_job_type_id'])
                ->exists();
            if (! $jobTypeHasLinks) {
                continue;
            }

            $allowed = MaintenanceJobTypeAssetType::query()
                ->join('aset_tr_penerimaan_aset as aset', function ($join): void {
                    $join->on('aset.jenis_aset_id', '=', 'aset_m_maintenance_job_type_asset_type.jenis_aset_id')
                        ->on('aset.tenant_id', '=', 'aset_m_maintenance_job_type_asset_type.tenant_id');
                })
                ->where([
                    'aset_m_maintenance_job_type_asset_type.job_type_id' => $detail['maintenance_job_type_id'],
                    // `withTrashed`: aset yang sudah diarsipkan tetap ditolak, tetapi oleh
                    // pemeriksaan jangkauan organisasi di bawah, dengan pesannya sendiri.
                    'aset_m_maintenance_job_type_asset_type.jenis_aset_id' => Asset::withTrashed()
                        ->where('id', $detail['asset_id'])
                        ->value('jenis_aset_id'),
                    'aset.id' => $detail['asset_id'],
                ])->exists();
            if (! $allowed) {
                throw ValidationException::withMessages(['details' => 'Jenis pekerjaan tidak tersedia untuk jenis aset yang dipilih. Atur relasi jenis aset pada master maintenance terlebih dahulu.']);
            }
        }

        return $this->assetLocations($request, $this->idsOf($details, 'asset_id'));
    }

    /**
     * Aset dibaca lewat jangkauan organisasi, bukan sekadar tenant: pengguna tidak boleh
     * membuat pekerjaan atas aset milik unit yang tidak dapat ia lihat.
     *
     * @param  list<string>  $ids
     * @return array<string, ?string>
     */
    private function assetLocations(Request $request, array $ids): array
    {
        $query = Asset::query()->whereIn('id', $ids);
        app(OrganizationScope::class)->assetQuery($query, $request);
        $assets = $query->pluck('asset_location_id', 'id');
        if ($assets->count() !== count($ids)) {
            throw ValidationException::withMessages(['details' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }

        return $assets->all();
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Request $request, array $data, string $key, string $kode, bool $new = true): array
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
            'version' => $new ? 1 : null,
            'created_at' => $new ? now() : null,
            'updated_at' => now(),
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @param  array<string, ?string>  $locations
     */
    private function replaceJobLines(string $workOrderId, array $details, array $locations): void
    {
        $rows = collect($details)->values()->map(fn (array $detail, int $index): PemeliharaanAsetDetail => PemeliharaanAsetDetail::create([
            'pemeliharaan_aset_id' => $workOrderId,
            'line_number' => $index + 1,
            'asset_id' => $detail['asset_id'],
            // Lokasi disalin, bukan dirujuk lewat aset: aset boleh berpindah setelah
            // pekerjaan selesai dan riwayat harus tetap menunjuk tempat pengerjaan.
            'asset_location_id' => $locations[$detail['asset_id']] ?? null,
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
     * Nama master ikut dibaca agar daftar tidak perlu satu permintaan tambahan per baris.
     *
     * Hasilnya sengaja baris mentah, bukan model: jawabannya memuat kolom gabungan dari
     * beberapa tabel yang tidak dimiliki model mana pun. Penyaringan tenant tetap datang
     * dari scope model, yang sudah diterapkan sebelum query diturunkan.
     *
     * @param  Builder<PemeliharaanAset>  $query
     */
    private function withLookups(Builder $query): QueryBuilder
    {
        return $query
            ->leftJoin('aset_m_tipe_work_order as tipe', function ($join): void {
                $join->on('tipe.id', '=', self::TABEL.'.tipe_work_order_id')->on('tipe.tenant_id', '=', self::TABEL.'.tenant_id');
            })
            ->leftJoin('aset_m_tingkat_layanan as layanan', function ($join): void {
                $join->on('layanan.id', '=', self::TABEL.'.tingkat_layanan_id')->on('layanan.tenant_id', '=', self::TABEL.'.tenant_id');
            })
            ->selectSub(
                PemeliharaanAsetDetail::query()
                    ->selectRaw('count(*)')
                    ->whereColumn(self::TABEL_BARIS.'.pemeliharaan_aset_id', self::TABEL.'.id')
                    ->toBase(),
                'jumlah_baris',
            )
            ->addSelect([
                self::TABEL.'.*',
                'tipe.kode as tipe_work_order_kode', 'tipe.nama as tipe_work_order_nama',
                'tipe.satu_pekerja',
                'layanan.kode as tingkat_layanan_kode', 'layanan.nama as tingkat_layanan_nama',
            ])
            ->toBase();
    }

    /** @return Collection<int, stdClass> */
    private function jobLines(string $workOrderId): Collection
    {
        return PemeliharaanAsetDetail::query()
            ->leftJoin('aset_tr_penerimaan_aset as aset', function ($join): void {
                $join->on('aset.id', '=', self::TABEL_BARIS.'.asset_id')->on('aset.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_maintenance_job_type as pekerjaan', function ($join): void {
                $join->on('pekerjaan.id', '=', self::TABEL_BARIS.'.maintenance_job_type_id')->on('pekerjaan.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_trade as keahlian', function ($join): void {
                $join->on('keahlian.id', '=', self::TABEL_BARIS.'.trade_id')->on('keahlian.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_sebab_kerusakan as sebab', function ($join): void {
                $join->on('sebab.id', '=', self::TABEL_BARIS.'.sebab_kerusakan_id')->on('sebab.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_tindakan_perbaikan as tindakan', function ($join): void {
                $join->on('tindakan.id', '=', self::TABEL_BARIS.'.tindakan_perbaikan_id')->on('tindakan.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->where(self::TABEL_BARIS.'.pemeliharaan_aset_id', $workOrderId)
            ->orderBy(self::TABEL_BARIS.'.line_number')
            ->toBase()
            ->get([
                self::TABEL_BARIS.'.*',
                'aset.kode as asset_kode',
                'pekerjaan.kode as job_type_kode', 'pekerjaan.nama as job_type_nama',
                'keahlian.nama as trade_nama',
                'sebab.nama as sebab_kerusakan_nama',
                'tindakan.nama as tindakan_perbaikan_nama',
            ]);
    }

    private function workOrder(Request $request, string $id): stdClass
    {
        // Setiap kolom di sini disebut lengkap dengan nama tabelnya, dan itu keharusan:
        // `withLookups()` di bawah menyambung dua tabel master yang sama-sama punya kolom
        // `id`, sehingga `where('id', ...)` polos ditolak PostgreSQL dengan "column
        // reference id is ambiguous" — sebagai 500, bukan sebagai hasil yang salah.
        //
        // Selama tabel utamanya masih beralias `wo`, penyebutan polos tidak pernah ambigu.
        // Aliasnya dibuang karena penyaringan tenant disisipkan scope dengan nama tabel yang
        // sebenarnya; konsekuensinya seluruh penyebutan kolom di jalur ini ikut berubah.
        $query = PemeliharaanAset::query()->where(self::TABEL.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::TABEL.'.legal_entity_id', self::TABEL.'.responsible_org_unit_id');

        return $this->withLookups($query)->firstOrFail();
    }

    private function staleVersion(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'stale_version',
            'message' => 'Work order telah berubah. Muat ulang lalu coba lagi.',
        ]], 409);
    }

    private function creationKey(Request $request): string
    {
        return (string) validator(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:'.self::MAX_CREATION_KEY, 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate()['key'];
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
