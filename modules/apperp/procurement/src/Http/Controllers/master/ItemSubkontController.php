<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\ItemSubkont;

final class ItemSubkontController extends Controller
{
    /**
     * Tampilkan daftar seluruh item subkontraktor.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $query = ItemSubkont::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('kode_subkont');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('kode_subkont', 'like', "%{$search}%")
                    ->orWhere('nama_subkont', 'like', "%{$search}%")
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
     * Tampilkan detail satu item subkontraktor.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemSubkont::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /**
     * Tambah data item subkontraktor baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $validated = $request->validate([
            'kode_subkont' => ['required', 'string', 'max:100'],
            'nama_subkont' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Cek duplikasi kode subkont dalam tenant
        $exists = ItemSubkont::where('tenant_id', $tenantId)
            ->where('kode_subkont', $validated['kode_subkont'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode subkont '.$validated['kode_subkont'].' sudah terdaftar.',
                'errors' => [
                    'kode_subkont' => ['Kode subkont ini sudah digunakan.'],
                ],
            ], 422);
        }

        $record = ItemSubkont::create([
            'tenant_id' => $tenantId,
            'creation_key' => (string) Str::uuid(),
            'kode_subkont' => $validated['kode_subkont'],
            'nama_subkont' => $validated['nama_subkont'],
            'satuan' => $validated['satuan'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Item subkontraktor berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    /**
     * Perbarui data item subkontraktor.
     */
    public function update(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemSubkont::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'kode_subkont' => ['required', 'string', 'max:100'],
            'nama_subkont' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Pastikan kode unik selain dirinya sendiri
        $duplicate = ItemSubkont::where('tenant_id', $tenantId)
            ->where('kode_subkont', $validated['kode_subkont'])
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode subkont '.$validated['kode_subkont'].' sudah terdaftar.',
                'errors' => [
                    'kode_subkont' => ['Kode subkont ini sudah digunakan oleh data lain.'],
                ],
            ], 422);
        }

        $record->update([
            'kode_subkont' => $validated['kode_subkont'],
            'nama_subkont' => $validated['nama_subkont'],
            'satuan' => $validated['satuan'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? $record->aktif,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Item subkontraktor berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    /**
     * Toggle status aktif/non aktif item subkontraktor.
     */
    public function toggleStatus(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemSubkont::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->aktif = ! $record->aktif;
        $record->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status item subkontraktor berhasil diubah menjadi '.($record->aktif ? 'Aktif' : 'Non Aktif').'.',
            'data' => $record,
        ]);
    }

    /**
     * Hapus / arsipkan item subkontraktor (soft delete).
     */
    public function destroy(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = ItemSubkont::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Item subkontraktor berhasil diarsipkan.',
        ]);
    }
}
