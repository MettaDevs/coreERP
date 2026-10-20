<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\ItemJasa;

final class ItemJasaController extends Controller
{
    /**
     * Tampilkan daftar seluruh item jasa/upah.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $query = ItemJasa::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('kode_upah');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('kode_upah', 'like', "%{$search}%")
                    ->orWhere('nama_upah', 'like', "%{$search}%")
                    ->orWhere('satuan', 'like', "%{$search}%")
                    ->orWhere('keterangan', 'like', "%{$search}%");
            });
        }

        if ($request->has('aktif')) {
            $query->where('aktif', filter_var($request->input('aktif'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Tampilkan detail satu item jasa/upah.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemJasa::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /**
     * Tambah data item jasa/upah baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $validated = $request->validate([
            'kode_upah' => ['required', 'string', 'max:100'],
            'nama_upah' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Cek duplikasi kode upah dalam tenant
        $exists = ItemJasa::where('tenant_id', $tenantId)
            ->where('kode_upah', $validated['kode_upah'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode upah '.$validated['kode_upah'].' sudah terdaftar.',
                'errors' => [
                    'kode_upah' => ['Kode upah ini sudah digunakan.'],
                ],
            ], 422);
        }

        $record = ItemJasa::create([
            'tenant_id' => $tenantId,
            'creation_key' => (string) Str::uuid(),
            'kode_upah' => $validated['kode_upah'],
            'nama_upah' => $validated['nama_upah'],
            'satuan' => $validated['satuan'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Item jasa/upah berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    /**
     * Perbarui data item jasa/upah.
     */
    public function update(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemJasa::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'kode_upah' => ['required', 'string', 'max:100'],
            'nama_upah' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Pastikan kode unik selain dirinya sendiri
        $duplicate = ItemJasa::where('tenant_id', $tenantId)
            ->where('kode_upah', $validated['kode_upah'])
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode upah '.$validated['kode_upah'].' sudah terdaftar.',
                'errors' => [
                    'kode_upah' => ['Kode upah ini sudah digunakan oleh data lain.'],
                ],
            ], 422);
        }

        $record->update([
            'kode_upah' => $validated['kode_upah'],
            'nama_upah' => $validated['nama_upah'],
            'satuan' => $validated['satuan'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? $record->aktif,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Item jasa/upah berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    /**
     * Toggle status aktif/non aktif item jasa/upah.
     */
    public function toggleStatus(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemJasa::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->aktif = ! $record->aktif;
        $record->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status item jasa/upah berhasil diubah menjadi '.($record->aktif ? 'Aktif' : 'Non Aktif').'.',
            'data' => $record,
        ]);
    }

    /**
     * Hapus / arsipkan item jasa/upah (soft delete).
     */
    public function destroy(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemJasa::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Item jasa/upah berhasil diarsipkan.',
        ]);
    }
}
