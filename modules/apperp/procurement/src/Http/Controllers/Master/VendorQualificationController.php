<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\Master;

use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\TenantContext;
use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Apperp\Procurement\Models\Master\VendorPrequalification;
use Modules\Apperp\Procurement\Models\Master\VendorPrequalificationDocument;
use Modules\Apperp\Procurement\Models\Master\VendorPrequalificationItem;

final class VendorQualificationController extends Controller
{
    private const QUALIFIED_STATUS = 'Lolos';

    /**
     * Daftar rekanan yang telah lolos evaluasi kualifikasi secara bertahap (cursor/incremental)
     * tanpa memuat rincian dokumen dan item pada setiap baris daftar.
     */
    public function index(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
        VendorDirectory $vendors,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.kualifikasi.read');
        $tenantId = $tenant->tenantId();

        $filters = $request->validate([
            'status_vendor' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = $this->qualifiedQuery($tenantId)
            ->select([
                'id',
                'tenant_id',
                'nomor_prakualifikasi',
                'tanggal_prakualifikasi',
                'vendor_id',
                'kode_vendor',
                'nama_vendor',
                'jenis_vendor',
                'nama_pic',
                'telepon',
                'alamat',
                'status_vendor',
                'status_badan_hukum',
                'status_kualifikasi',
                'version',
                'created_at',
                'updated_at',
            ]);

        if (($filters['status_vendor'] ?? '') !== '' && ($filters['status_vendor'] ?? null) !== null) {
            $query->where('status_vendor', $filters['status_vendor']);
        }

        if (($filters['start_date'] ?? '') !== '' && ($filters['start_date'] ?? null) !== null) {
            $query->whereDate('tanggal_prakualifikasi', '>=', $filters['start_date']);
        }

        if (($filters['end_date'] ?? '') !== '' && ($filters['end_date'] ?? null) !== null) {
            $query->whereDate('tanggal_prakualifikasi', '<=', $filters['end_date']);
        }

        $batch = IncrementalList::query(
            $request,
            $query->toBase(),
            [
                'id' => 'id',
                'tanggal_prakualifikasi' => 'tanggal_prakualifikasi',
                'nomor_prakualifikasi' => 'nomor_prakualifikasi',
                'kode_vendor' => 'kode_vendor',
                'nama_vendor' => 'nama_vendor',
                'status_kualifikasi' => 'status_kualifikasi',
            ],
            [
                'nomor_prakualifikasi',
                'kode_vendor',
                'nama_vendor',
                'nama_pic',
                'telepon',
                'alamat',
            ],
            defaultSort: 'tanggal_prakualifikasi',
            defaultDirection: 'desc',
        );

        $vendorIds = array_values(
            $batch['data']
                ->pluck('vendor_id')
                ->filter()
                ->map(strval(...))
                ->unique()
                ->all(),
        );
        $vendorMap = $vendorIds === [] ? [] : $vendors->findMany($tenantId, $vendorIds);

        $batch['data'] = VendorPrequalification::hydrate(
            $batch['data']->map(static fn ($row): array => (array) $row)->all(),
        )->map(fn (VendorPrequalification $record): array => $this->presentSummary($record, $vendorMap))->values();

        return response()->json($batch);
    }

    /**
     * Detail lengkap rekanan yang telah lolos kualifikasi beserta dokumen dan item.
     * Memakai syarat keanggotaan resource yang sama dengan daftar (status_kualifikasi = Lolos).
     */
    public function show(
        RequestContext $context,
        TenantContext $tenant,
        VendorDirectory $vendors,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.kualifikasi.read');
        $tenantId = $tenant->tenantId();

        $record = $this->qualifiedQuery($tenantId)
            ->with(['documents', 'items'])
            ->findOrFail($id);

        $vendor = $vendors->find($tenantId, $record->vendor_id);

        return response()->json(
            ['data' => $this->presentDetail($record, $vendor)],
            200,
            ['ETag' => RowVersion::etag($record->version)],
        );
    }

    /**
     * Syarat keanggotaan resource Kualifikasi yang dipakai bersama oleh daftar dan detail.
     *
     * @return Builder<VendorPrequalification>
     */
    private function qualifiedQuery(string $tenantId): Builder
    {
        return VendorPrequalification::query()
            ->where('tenant_id', $tenantId)
            ->where('status_kualifikasi', self::QUALIFIED_STATUS);
    }

    private function requirePermission(RequestContext $context, string $permission): void
    {
        if ($context->hasPermission($permission)) {
            return;
        }

        abort(response()->json([
            'error' => [
                'code' => 'forbidden',
                'message' => 'Hak '.$permission.' belum dimiliki pengguna pada tenant aktif.',
            ],
        ], 403));
    }

    /**
     * Proyeksi ringkas untuk baris tabel daftar (tanpa menyertakan child documents/items).
     *
     * @param  array<string, array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}>  $vendorMap
     * @return array<string, mixed>
     */
    private function presentSummary(VendorPrequalification $record, array $vendorMap): array
    {
        $vendor = $vendorMap[$record->vendor_id] ?? null;

        return [
            'id' => $record->id,
            'nomor_prakualifikasi' => $record->nomor_prakualifikasi,
            'tanggal_prakualifikasi' => $record->tanggal_prakualifikasi?->format('Y-m-d') ?? (string) $record->getRawOriginal('tanggal_prakualifikasi'),
            'vendor_id' => $record->vendor_id,
            'kode_vendor' => $vendor['number'] ?? $record->kode_vendor,
            'nama_vendor' => $vendor['name'] ?? $record->nama_vendor,
            'jenis_vendor' => $record->jenis_vendor,
            'nama_pic' => $record->nama_pic,
            'telepon' => $record->telepon,
            'alamat' => $record->alamat,
            'status_vendor' => $record->status_vendor,
            'status_badan_hukum' => $record->status_badan_hukum,
            'status_kualifikasi' => $record->status_kualifikasi,
            'version' => $record->version,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    /**
     * Proyeksi lengkap untuk detail card (hanya 1 bentuk field `documents`, tanpa alias duplikat).
     *
     * @param  array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}|null  $vendor
     * @return array<string, mixed>
     */
    private function presentDetail(VendorPrequalification $record, ?array $vendor): array
    {
        return [
            'id' => $record->id,
            'nomor_prakualifikasi' => $record->nomor_prakualifikasi,
            'tanggal_prakualifikasi' => $record->tanggal_prakualifikasi?->format('Y-m-d') ?? (string) $record->getRawOriginal('tanggal_prakualifikasi'),
            'vendor_id' => $record->vendor_id,
            'kode_vendor' => $vendor['number'] ?? $record->kode_vendor,
            'nama_vendor' => $vendor['name'] ?? $record->nama_vendor,
            'jenis_vendor' => $record->jenis_vendor,
            'nama_pic' => $record->nama_pic,
            'npwp_ktp' => $record->npwp_ktp ?? ($vendor['tax_number'] ?? null),
            'telepon' => $record->telepon,
            'email' => $record->email,
            'media_sosial' => $record->media_sosial,
            'alamat' => $record->alamat,
            'status_vendor' => $record->status_vendor,
            'status_badan_hukum' => $record->status_badan_hukum,
            'status_kualifikasi' => $record->status_kualifikasi,
            'waktu_kesediaan_item' => $record->waktu_kesediaan_item,
            'jumlah_karyawan' => $record->jumlah_karyawan,
            'catatan' => $record->catatan,
            'version' => $record->version,
            'documents' => $record->documents->map(static fn (VendorPrequalificationDocument $doc): array => [
                'id' => $doc->id,
                'kategori' => $doc->kategori,
                'urutan' => $doc->urutan,
                'nama_dokumen' => $doc->nama_dokumen,
                'ada' => (bool) $doc->ada,
                'keterangan' => $doc->keterangan,
            ])->values()->all(),
            'items' => $record->items->map(static fn (VendorPrequalificationItem $item): array => [
                'id' => $item->id,
                'urutan' => $item->urutan,
                'jenis_item' => $item->jenis_item,
                'kode_item' => $item->kode_item,
                'nama_item' => $item->nama_item,
                'satuan' => $item->satuan,
                'kesediaan' => $item->kesediaan,
                'keterangan' => $item->keterangan,
            ])->values()->all(),
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }
}
