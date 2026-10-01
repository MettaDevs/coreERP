<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MaintenanceRequest;

use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceRequestType;
use Modules\Apperp\ManagementAset\Models\master\SebabKerusakan;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceRequest\MaintenanceRequest;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\WorkOrderCreator;
use Modules\Apperp\ManagementAset\Support\MaintenanceRequestStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Permintaan pemeliharaan; padanan *Maintenance requests* di Dynamics 365 F&O Asset Management.
 *
 * Setiap perpindahan status adalah tindakan tersendiri — ajukan, terima, tolak, buat work order —
 * bukan efek samping menyimpan, seperti tombol pada dokumen BC dan F&O. Permission-nya pun berbeda:
 * pelapor menyusun dan mengajukan, perencana memutuskan. Membuat work order juga menuntut hak membuat
 * work order, jadi pelapor yang hanya boleh mengajukan tidak dapat melompati perencana.
 *
 * Kebijakan organisasi mengikuti unit pelapor (`responsible_org_unit_id`), sama seperti dokumen
 * transaksi aset lainnya.
 */
final class MaintenanceRequestController extends Controller
{
    private const RESOURCE = 'permintaan-pemeliharaan';

    private const TABLE = 'aset_tr_permintaan_pemeliharaan';

    /** Core membatasi kunci 160 karakter, dan kunci nomor diawali `permintaan-pemeliharaan:` (24). */
    private const MAX_CREATION_KEY = 135;

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate(['status' => ['nullable', Rule::in(MaintenanceRequestStatus::ALL)]]);
        $query = $this->withLookups(MaintenanceRequest::query());
        $this->scope($query, $request);
        if ($filter['status'] ?? null) {
            $query->where(self::TABLE.'.status', $filter['status']);
        }

        return response()->json(['data' => $query->orderByDesc(self::TABLE.'.created_at')->limit(500)->toBase()->get()]);
    }

    public function show(Request $request, string $id, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'read');
        $record = $this->find($request, $id);
        $tenant = $this->tenant($request);
        $record->unit_nama = $people->unitName($tenant, $record->responsible_org_unit_id);
        $record->dilaporkan_oleh_nama = $people->personName($tenant, $record->created_by_user_id === null ? null : (string) $record->created_by_user_id);
        $record->diputuskan_oleh_nama = $people->personName($tenant, $record->diputuskan_oleh_user_id);

        return response()->json(['data' => $record], 200, ['ETag' => RowVersion::etag((int) $record->version)]);
    }

    public function store(Request $request, AssetNumberSequenceIssuer $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = (string) validator(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:'.self::MAX_CREATION_KEY, 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate()['key'];
        if ($existing = $this->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $values = $this->lookups($request, $data);

        try {
            DB::transaction(function () use ($request, $numbers, $data, $values, $key): void {
                $kode = $numbers->issue('management-aset.'.self::RESOURCE, $this->tenant($request), self::RESOURCE.':'.$key, $data['legal_entity_id']);
                MaintenanceRequest::query()->create([
                    ...$values,
                    'tenant_id' => $this->tenant($request),
                    'creation_key' => $key,
                    'kode' => $kode,
                    'status' => MaintenanceRequestStatus::DRAFT,
                ]);
            });
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        } catch (QueryException $exception) {
            $existing = $this->replay($key);
            if (! $existing) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $created = $this->replay($key);

        return response()->json(['data' => $created], 201, ['Location' => $request->url().'/'.$created?->id]);
    }

    public function update(Request $request, string $id, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'update');
        $record = $this->find($request, $id);
        abort_unless($record->status === MaintenanceRequestStatus::DRAFT, 422, 'Hanya permintaan draf yang dapat diubah.');
        $data = $this->validated($request);
        $version = RowVersion::expected($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $values = $this->lookups($request, $data);

        DB::transaction(function () use ($id, $version, $values): void {
            RowVersion::claim(MaintenanceRequest::query()->whereKey($id), $version);
            MaintenanceRequest::query()->whereKey($id)->update([...$values, 'updated_at' => now()]);
        });

        return $this->show($request, $id, $people);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $record = $this->find($request, $id);
        abort_unless(
            in_array($record->status, MaintenanceRequestStatus::ARCHIVABLE, true),
            422,
            'Hanya permintaan draf atau yang ditolak yang dapat diarsipkan.',
        );
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(MaintenanceRequest::query()->whereKey($id), $version);
            MaintenanceRequest::query()->whereKey($id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    public function submit(Request $request, string $id, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'submit');

        return $this->move($request, $id, $people, MaintenanceRequestStatus::DRAFT, [
            'status' => MaintenanceRequestStatus::SUBMITTED,
            'diajukan_pada' => now(),
        ], 'Hanya permintaan draf yang dapat diajukan.');
    }

    public function accept(Request $request, string $id, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'review');

        return $this->move($request, $id, $people, MaintenanceRequestStatus::SUBMITTED, [
            'status' => MaintenanceRequestStatus::ACCEPTED,
            'diputuskan_pada' => now(),
            'diputuskan_oleh_user_id' => app(RequestContext::class)->userId(),
        ], 'Hanya permintaan yang sudah diajukan yang dapat diterima.');
    }

    public function reject(Request $request, string $id, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'review');
        $reason = $request->validate(['alasan' => ['required', 'string', 'max:2000']], [
            'alasan.required' => 'Tulis alasan penolakan supaya pelapor tahu langkah berikutnya.',
        ])['alasan'];

        return $this->move($request, $id, $people, MaintenanceRequestStatus::SUBMITTED, [
            'status' => MaintenanceRequestStatus::REJECTED,
            'diputuskan_pada' => now(),
            'diputuskan_oleh_user_id' => app(RequestContext::class)->userId(),
            'alasan_penolakan' => trim($reason),
        ], 'Hanya permintaan yang sudah diajukan yang dapat ditolak.');
    }

    /**
     * Membuat satu work order draf dari permintaan yang sudah diterima; padanan tindakan *Work order*
     * pada permintaan F&O. Aset boleh dipilih di sini bila pelapor hanya menyebut lokasi.
     */
    public function createWorkOrder(Request $request, string $id, WorkOrderCreator $creator, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'review');
        abort_unless(
            in_array('management-aset.pemeliharaan-aset.create', $request->attributes->get('coreerp.permissions', []), true),
            403,
            'Membuat work order dari permintaan membutuhkan hak membuat work order pemeliharaan.',
        );
        $record = $this->find($request, $id);
        abort_unless($record->status === MaintenanceRequestStatus::ACCEPTED, 422, 'Work order hanya dapat dibuat dari permintaan yang sudah diterima.');
        $data = $request->validate([
            'aset_id' => ['nullable', 'ulid'],
            'maintenance_job_type_id' => ['required', 'ulid'],
            'variant_id' => ['nullable', 'ulid'],
            'trade_id' => ['nullable', 'ulid'],
            'tipe_work_order_id' => ['nullable', 'ulid'],
        ]);
        $version = RowVersion::expected($request);

        $asetId = $record->aset_id ?? ($data['aset_id'] ?? null);
        if ($asetId === null) {
            throw ValidationException::withMessages(['aset_id' => 'Pilih aset yang dikerjakan. Permintaan ini hanya menyebut lokasinya.']);
        }
        $tipe = $data['tipe_work_order_id'] ?? MaintenanceRequestType::withTrashed()->whereKey($record->jenis_permintaan_id)->value('tipe_work_order_id');
        if ($tipe === null) {
            throw ValidationException::withMessages(['tipe_work_order_id' => 'Pilih tipe work order. Jenis permintaan ini tidak menyebut tipe bawaannya.']);
        }

        try {
            DB::transaction(function () use ($request, $creator, $record, $data, $asetId, $tipe, $version, $id): void {
                RowVersion::claim(MaintenanceRequest::query()->whereKey($id), $version);
                $result = $creator->create($request, [
                    'legal_entity_id' => $record->legal_entity_id,
                    'responsible_org_unit_id' => $record->responsible_org_unit_id,
                    'tipe_work_order_id' => $tipe,
                    'tingkat_layanan_id' => $record->tingkat_layanan_id,
                    'keterangan' => $record->kode.': '.$record->deskripsi,
                    'details' => [[
                        'aset_id' => $asetId,
                        'maintenance_job_type_id' => $data['maintenance_job_type_id'],
                        'variant_id' => $data['variant_id'] ?? null,
                        'trade_id' => $data['trade_id'] ?? null,
                    ]],
                ], 'permintaan:'.$id);
                MaintenanceRequest::query()->whereKey($id)->update([
                    'aset_id' => $asetId,
                    'pemeliharaan_aset_id' => $result['record']->id,
                    'status' => MaintenanceRequestStatus::WORK_ORDER_CREATED,
                    'updated_at' => now(),
                ]);
            });
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        return $this->show($request, $id, $people);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function move(Request $request, string $id, AssetOrganizationDirectory $people, string $from, array $changes, string $message): JsonResponse
    {
        $record = $this->find($request, $id);
        abort_unless($record->status === $from, 422, $message);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version, $changes): void {
            RowVersion::claim(MaintenanceRequest::query()->whereKey($id), $version);
            MaintenanceRequest::query()->whereKey($id)->update([...$changes, 'updated_at' => now()]);
        });

        return $this->show($request, $id, $people);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['required', 'ulid'],
            'jenis_permintaan_id' => ['required', 'ulid'],
            'aset_id' => ['nullable', 'ulid', 'required_without:lokasi_aset_id'],
            'lokasi_aset_id' => ['nullable', 'ulid'],
            'deskripsi' => ['required', 'string', 'max:4000'],
            'tingkat_layanan_id' => ['nullable', 'ulid'],
            'sebab_kerusakan_id' => ['nullable', 'ulid'],
        ], [
            'aset_id.required_without' => 'Pilih aset atau lokasi yang dilaporkan.',
        ]);
    }

    /**
     * Master yang dirujuk wajib aktif, dan aset wajib berada dalam jangkauan organisasi pengguna serta
     * masih beredar. Lokasi yang kosong diisi lokasi aset saat ini, supaya perencana langsung tahu
     * tempatnya.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function lookups(Request $request, array $data): array
    {
        $this->requireActive(MaintenanceRequestType::class, $data['jenis_permintaan_id'], 'jenis_permintaan_id', 'Jenis permintaan tidak ditemukan atau sudah tidak aktif.');
        foreach ([
            'tingkat_layanan_id' => [TingkatLayanan::class, 'Tingkat layanan tidak ditemukan atau sudah tidak aktif.'],
            'sebab_kerusakan_id' => [SebabKerusakan::class, 'Sebab kerusakan tidak ditemukan atau sudah tidak aktif.'],
            'lokasi_aset_id' => [LokasiAset::class, 'Lokasi tidak ditemukan atau sudah tidak aktif.'],
        ] as $field => [$model, $message]) {
            if (($data[$field] ?? null) !== null) {
                $this->requireActive($model, $data[$field], $field, $message);
            }
        }

        $lokasi = $data['lokasi_aset_id'] ?? null;
        if (($data['aset_id'] ?? null) !== null) {
            $query = Aset::query()->whereKey($data['aset_id']);
            app(OrganizationScope::class)->asetQuery($query, $request);
            $aset = $query->first();
            if ($aset === null) {
                throw ValidationException::withMessages(['aset_id' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
            }
            if (! StatusAset::bolehDibuatkanWorkOrder($aset->lifecycle_state)) {
                throw ValidationException::withMessages(['aset_id' => 'Aset '.$aset->kode.' sudah dihentikan atau dilepas, sehingga tidak dapat dimintakan pemeliharaan.']);
            }
            $lokasi ??= $aset->lokasi_aset_id;
        }

        return [
            'legal_entity_id' => $data['legal_entity_id'],
            'responsible_org_unit_id' => $data['responsible_org_unit_id'],
            'jenis_permintaan_id' => $data['jenis_permintaan_id'],
            'aset_id' => $data['aset_id'] ?? null,
            'lokasi_aset_id' => $lokasi,
            'deskripsi' => trim($data['deskripsi']),
            'tingkat_layanan_id' => $data['tingkat_layanan_id'] ?? null,
            'sebab_kerusakan_id' => $data['sebab_kerusakan_id'] ?? null,
        ];
    }

    /** @param  class-string<Model>  $model */
    private function requireActive(string $model, string $id, string $field, string $message): void
    {
        if (! $model::query()->whereKey($id)->where('aktif', true)->exists()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function find(Request $request, string $id): stdClass
    {
        $query = $this->withLookups(MaintenanceRequest::query())->where(self::TABLE.'.id', $id);
        $this->scope($query, $request);

        return $query->toBase()->first() ?? abort(404);
    }

    private function replay(string $key): ?stdClass
    {
        return $this->withLookups(MaintenanceRequest::withTrashed())->where(self::TABLE.'.creation_key', $key)->toBase()->first();
    }

    /** @param  Builder<MaintenanceRequest>  $query */
    private function scope(Builder $query, Request $request): void
    {
        app(OrganizationScope::class)->query($query, $request, self::TABLE.'.legal_entity_id', self::TABLE.'.responsible_org_unit_id');
    }

    /**
     * Permintaan beserta nama master, aset, lokasi, dan nomor work order-nya, supaya daftar tidak
     * membutuhkan satu permintaan tambahan per baris.
     *
     * @param  Builder<MaintenanceRequest>  $query
     * @return Builder<MaintenanceRequest>
     */
    private function withLookups(Builder $query): Builder
    {
        $join = static fn (string $alias, string $column) => function ($join) use ($alias, $column): void {
            $join->on($alias.'.id', '=', self::TABLE.'.'.$column)->on($alias.'.tenant_id', '=', self::TABLE.'.tenant_id');
        };

        return $query
            ->leftJoin('aset_m_jenis_permintaan_pemeliharaan as jenis', $join('jenis', 'jenis_permintaan_id'))
            ->leftJoin('aset_tr_aset as aset', $join('aset', 'aset_id'))
            ->leftJoin('aset_m_lokasi_aset as lokasi', $join('lokasi', 'lokasi_aset_id'))
            ->leftJoin('aset_m_tingkat_layanan as layanan', $join('layanan', 'tingkat_layanan_id'))
            ->leftJoin('aset_m_sebab_kerusakan as sebab', $join('sebab', 'sebab_kerusakan_id'))
            ->leftJoin('aset_tr_pemeliharaan_aset as wo', $join('wo', 'pemeliharaan_aset_id'))
            ->select([
                self::TABLE.'.*',
                'jenis.kode as jenis_permintaan_kode', 'jenis.nama as jenis_permintaan_nama',
                'jenis.tipe_work_order_id as jenis_permintaan_tipe_work_order_id',
                'aset.kode as aset_kode', 'aset.nama as aset_nama', 'aset.jenis_aset_id as aset_jenis_aset_id',
                'lokasi.kode as lokasi_kode', 'lokasi.nama as lokasi_nama',
                'layanan.nama as tingkat_layanan_nama',
                'sebab.nama as sebab_kerusakan_nama',
                'wo.kode as work_order_kode', 'wo.status as work_order_status',
            ]);
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
