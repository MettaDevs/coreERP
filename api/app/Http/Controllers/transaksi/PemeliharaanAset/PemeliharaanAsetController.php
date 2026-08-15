<?php

namespace App\Http\Controllers\transaksi\PemeliharaanAset;

use App\Http\Controllers\Controller;
use App\Services\NumberSequenceClient;
use App\Services\NumberSequenceException;
use App\Support\OrganizationScope;
use App\Support\WorkOrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Work order pemeliharaan aset.
 *
 * Header memikul dokumen dan jadwalnya; aset justru berada di baris pekerjaan, mengikuti
 * Dynamics 365 F&O, sehingga satu perintah kerja dapat mencakup beberapa aset. Perpindahan
 * status dan pengisian checklist tidak ditangani di sini melainkan di controller
 * pelaksanaan, supaya penyuntingan dokumen dan pengerjaan lapangan tidak berbagi jalur.
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

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $tenant = $this->tenant($request);

        $query = DB::table('tr_pemeliharaan_aset as wo')
            ->where('wo.tenant_id', $tenant)->whereNull('wo.deleted_at');
        app(OrganizationScope::class)->query($query, $request, 'wo.legal_entity_id', 'wo.responsible_org_unit_id');

        return response()->json(['data' => $this->withLookups($query, $tenant)
            ->orderByDesc('wo.created_at')
            ->get()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $workOrder = $this->workOrder($request, $id);
        $workOrder->details = $this->jobLines($this->tenant($request), $id);
        $workOrder->status_log = DB::table('tr_pemeliharaan_aset_status_log')
            ->where(['tenant_id' => $this->tenant($request), 'pemeliharaan_aset_id' => $id])
            ->orderBy('created_at')->get();

        return response()->json(['data' => $workOrder]);
    }

    public function store(Request $request, NumberSequenceClient $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = DB::table('tr_pemeliharaan_aset')->where(['tenant_id' => $tenant, 'creation_key' => $key])->first()) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $locations = $this->validateLookups($request, $tenant, $data);

        // Nomor diterbitkan setelah seluruh validasi supaya permintaan yang ditolak tidak
        // pernah membakar counter, dan sebelum transaksi supaya kegagalan Core tidak
        // menahan koneksi database.
        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->status);
        }

        try {
            $workOrder = DB::transaction(function () use ($request, $data, $key, $tenant, $kode, $locations): array {
                $record = $this->header($request, $data, $key, $kode);
                DB::table('tr_pemeliharaan_aset')->insert($record);
                $this->replaceJobLines($record['id'], $tenant, $data['details'], $locations);

                return $record;
            });
        } catch (QueryException $exception) {
            $existing = DB::table('tr_pemeliharaan_aset')->where(['tenant_id' => $tenant, 'creation_key' => $key])->first();
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
        $tenant = $this->tenant($request);
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
        $locations = $this->validateLookups($request, $tenant, $data);

        $changed = DB::transaction(function () use ($request, $id, $tenant, $version, $data, $locations): int {
            $updated = DB::table('tr_pemeliharaan_aset')->where([
                'id' => $id, 'tenant_id' => $tenant, 'version' => $version,
            ])->whereNull('deleted_at')->update([
                ...$this->header($request, $data, '', '', false),
                'version' => $version + 1,
                'updated_at' => now(),
            ]);
            if ($updated) {
                DB::table('tr_pemeliharaan_aset_details')->where(['tenant_id' => $tenant, 'pemeliharaan_aset_id' => $id])->delete();
                $this->replaceJobLines($id, $tenant, $data['details'], $locations);
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

        $updated = DB::table('tr_pemeliharaan_aset')->where([
            'id' => $id, 'tenant_id' => $this->tenant($request), 'version' => $version,
        ])->whereNull('deleted_at')->update(['deleted_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);

        return $updated ? response()->json(status: 204) : $this->staleVersion();
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
     * Seluruh master dan aset yang dirujuk wajib milik tenant yang sama, aktif, dan berada
     * dalam jangkauan organisasi pengguna. Mengembalikan lokasi aset saat ini untuk
     * disalin ke baris pekerjaan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    private function validateLookups(Request $request, string $tenant, array $data): array
    {
        $this->requireActiveMaster('m_tipe_work_order', $tenant, [$data['tipe_work_order_id']], 'tipe_work_order_id', 'Tipe work order tidak ditemukan atau sudah tidak aktif.');
        if ($data['tingkat_layanan_id'] ?? null) {
            $this->requireActiveMaster('m_tingkat_layanan', $tenant, [$data['tingkat_layanan_id']], 'tingkat_layanan_id', 'Tingkat layanan tidak ditemukan atau sudah tidak aktif.');
        }

        $details = $data['details'];
        $this->requireActiveMaster('m_maintenance_job_type', $tenant, $this->idsOf($details, 'maintenance_job_type_id'), 'details', 'Jenis pekerjaan maintenance tidak ditemukan atau sudah tidak aktif.');
        $this->requireActiveMaster('m_trade', $tenant, $this->idsOf($details, 'trade_id'), 'details', 'Bidang keahlian tidak ditemukan atau sudah tidak aktif.');

        // Varian harus milik jenis pekerjaan pada baris yang sama; varian dari job type lain
        // akan lolos pemeriksaan keberadaan biasa dan diam-diam salah pasang.
        foreach ($details as $detail) {
            if (($detail['variant_id'] ?? null) === null) {
                continue;
            }
            $matches = DB::table('m_maintenance_job_type_variant')
                ->where([
                    'tenant_id' => $tenant,
                    'id' => $detail['variant_id'],
                    'maintenance_job_type_id' => $detail['maintenance_job_type_id'],
                    'aktif' => true,
                ])->whereNull('deleted_at')->exists();
            if (! $matches) {
                throw ValidationException::withMessages(['details' => 'Varian pekerjaan harus berasal dari jenis pekerjaan yang dipilih pada baris yang sama.']);
            }
        }

        return $this->assetLocations($request, $tenant, $this->idsOf($details, 'asset_id'));
    }

    /**
     * Aset dibaca lewat jangkauan organisasi, bukan sekadar tenant: pengguna tidak boleh
     * membuat pekerjaan atas aset milik unit yang tidak dapat ia lihat.
     *
     * @param  list<string>  $ids
     * @return array<string, ?string>
     */
    private function assetLocations(Request $request, string $tenant, array $ids): array
    {
        $query = DB::table('tr_penerimaan_aset')->where('tenant_id', $tenant)->whereIn('id', $ids)->whereNull('deleted_at');
        app(OrganizationScope::class)->assetQuery($query, $request);
        $assets = $query->pluck('asset_location_id', 'id');
        if ($assets->count() !== count($ids)) {
            throw ValidationException::withMessages(['details' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }

        return $assets->all();
    }

    /** @param list<string> $ids */
    private function requireActiveMaster(string $table, string $tenant, array $ids, string $field, string $message): void
    {
        if ($ids === []) {
            return;
        }
        $found = DB::table($table)->where('tenant_id', $tenant)->whereIn('id', $ids)
            ->where('aktif', true)->whereNull('deleted_at')->count();
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
    private function replaceJobLines(string $workOrderId, string $tenant, array $details, array $locations): void
    {
        DB::table('tr_pemeliharaan_aset_details')->insert(collect($details)->values()->map(fn (array $detail, int $index): array => [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant,
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
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    /** Nama master ikut dibaca agar daftar tidak perlu satu permintaan tambahan per baris. */
    private function withLookups(mixed $query, string $tenant): mixed
    {
        return $query
            ->leftJoin('m_tipe_work_order as tipe', function ($join): void {
                $join->on('tipe.id', '=', 'wo.tipe_work_order_id')->on('tipe.tenant_id', '=', 'wo.tenant_id');
            })
            ->leftJoin('m_tingkat_layanan as layanan', function ($join): void {
                $join->on('layanan.id', '=', 'wo.tingkat_layanan_id')->on('layanan.tenant_id', '=', 'wo.tenant_id');
            })
            ->selectSub(
                DB::table('tr_pemeliharaan_aset_details')
                    ->selectRaw('count(*)')
                    ->whereColumn('tr_pemeliharaan_aset_details.pemeliharaan_aset_id', 'wo.id')
                    ->where('tr_pemeliharaan_aset_details.tenant_id', $tenant),
                'jumlah_baris',
            )
            ->addSelect([
                'wo.*',
                'tipe.kode as tipe_work_order_kode', 'tipe.nama as tipe_work_order_nama',
                'tipe.satu_pekerja',
                'layanan.kode as tingkat_layanan_kode', 'layanan.nama as tingkat_layanan_nama',
            ]);
    }

    /** @return Collection<int, object> */
    private function jobLines(string $tenant, string $workOrderId): mixed
    {
        return DB::table('tr_pemeliharaan_aset_details as job')
            ->leftJoin('tr_penerimaan_aset as aset', function ($join): void {
                $join->on('aset.id', '=', 'job.asset_id')->on('aset.tenant_id', '=', 'job.tenant_id');
            })
            ->leftJoin('m_maintenance_job_type as pekerjaan', function ($join): void {
                $join->on('pekerjaan.id', '=', 'job.maintenance_job_type_id')->on('pekerjaan.tenant_id', '=', 'job.tenant_id');
            })
            ->leftJoin('m_trade as keahlian', function ($join): void {
                $join->on('keahlian.id', '=', 'job.trade_id')->on('keahlian.tenant_id', '=', 'job.tenant_id');
            })
            ->where(['job.tenant_id' => $tenant, 'job.pemeliharaan_aset_id' => $workOrderId])
            ->orderBy('job.line_number')
            ->get([
                'job.*',
                'aset.kode as asset_kode',
                'pekerjaan.kode as job_type_kode', 'pekerjaan.nama as job_type_nama',
                'keahlian.nama as trade_nama',
            ]);
    }

    private function workOrder(Request $request, string $id): object
    {
        $tenant = $this->tenant($request);
        $query = DB::table('tr_pemeliharaan_aset as wo')
            ->where(['wo.id' => $id, 'wo.tenant_id' => $tenant])->whereNull('wo.deleted_at');
        app(OrganizationScope::class)->query($query, $request, 'wo.legal_entity_id', 'wo.responsible_org_unit_id');

        return $this->withLookups($query, $tenant)->firstOrFail();
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
