<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\KategoriPengadaan;

final class KategoriPengadaanController extends Controller
{
    /**
     * Tampilkan daftar seluruh kategori pengadaan.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $query = KategoriPengadaan::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('kode_pengadaan');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('kode_pengadaan', 'like', "%{$search}%")
                    ->orWhere('kategori_pengadaan', 'like', "%{$search}%")
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
     * Tampilkan detail satu kategori pengadaan.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = KategoriPengadaan::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /**
     * Tambah data kategori pengadaan baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $validated = $request->validate([
            'kode_pengadaan' => ['required', 'string', 'max:100'],
            'kategori_pengadaan' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Cek duplikasi kode pengadaan dalam tenant
        $exists = KategoriPengadaan::where('tenant_id', $tenantId)
            ->where('kode_pengadaan', $validated['kode_pengadaan'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode pengadaan '.$validated['kode_pengadaan'].' sudah terdaftar.',
                'errors' => [
                    'kode_pengadaan' => ['Kode pengadaan ini sudah digunakan.'],
                ],
            ], 422);
        }

        $record = KategoriPengadaan::create([
            'tenant_id' => $tenantId,
            'creation_key' => (string) Str::uuid(),
            'kode_pengadaan' => $validated['kode_pengadaan'],
            'kategori_pengadaan' => $validated['kategori_pengadaan'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Kategori pengadaan berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    /**
     * Perbarui data kategori pengadaan.
     */
    public function update(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = KategoriPengadaan::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'kode_pengadaan' => ['required', 'string', 'max:100'],
            'kategori_pengadaan' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Pastikan kode unik selain dirinya sendiri
        $duplicate = KategoriPengadaan::where('tenant_id', $tenantId)
            ->where('kode_pengadaan', $validated['kode_pengadaan'])
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode pengadaan '.$validated['kode_pengadaan'].' sudah terdaftar.',
                'errors' => [
                    'kode_pengadaan' => ['Kode pengadaan ini sudah digunakan oleh data lain.'],
                ],
            ], 422);
        }

        $record->update([
            'kode_pengadaan' => $validated['kode_pengadaan'],
            'kategori_pengadaan' => $validated['kategori_pengadaan'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? $record->aktif,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Kategori pengadaan berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    /**
     * Toggle status aktif/non aktif kategori pengadaan.
     */
    public function toggleStatus(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = KategoriPengadaan::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->aktif = ! $record->aktif;
        $record->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status kategori pengadaan berhasil diubah menjadi '.($record->aktif ? 'Aktif' : 'Non Aktif').'.',
            'data' => $record,
        ]);
    }

    /**
     * Hapus / arsipkan kategori pengadaan (soft delete).
     */
    public function destroy(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = KategoriPengadaan::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Kategori pengadaan berhasil diarsipkan.',
        ]);
    }
}
