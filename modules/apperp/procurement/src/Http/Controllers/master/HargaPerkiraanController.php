<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\HargaPerkiraan;

final class HargaPerkiraanController extends Controller
{
    /**
     * Daftar harga perkiraan (HPS) barang, upah, asset, subkont, dan overhead.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $query = HargaPerkiraan::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('kode_item');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('kode_item', 'like', "%{$search}%")
                    ->orWhere('nama_item', 'like', "%{$search}%")
                    ->orWhere('group_item', 'like', "%{$search}%")
                    ->orWhere('jenis_item', 'like', "%{$search}%");
            });
        }

        if ($group = $request->input('group_item')) {
            $query->where('group_item', $group);
        }

        if ($jenis = $request->input('jenis_item')) {
            $query->where('jenis_item', $jenis);
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
     * Ambil detail data satu item harga perkiraan.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = HargaPerkiraan::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /**
     * Tambah data item harga perkiraan baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $validated = $request->validate([
            'group_item' => ['required', 'string', 'max:100'],
            'jenis_item' => ['required', 'string', 'max:100'],
            'kode_item' => ['required', 'string', 'max:100'],
            'nama_item' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'harga_perkiraan' => ['required', 'numeric', 'min:0'],
            'tanggal_update' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string'],
        ]);

        $record = HargaPerkiraan::create([
            'tenant_id' => $tenantId,
            'creation_key' => (string) Str::uuid(),
            'group_item' => $validated['group_item'],
            'jenis_item' => $validated['jenis_item'],
            'kode_item' => $validated['kode_item'],
            'nama_item' => $validated['nama_item'],
            'satuan' => $validated['satuan'],
            'harga_perkiraan' => $validated['harga_perkiraan'],
            'tanggal_update' => $validated['tanggal_update'] ?? Carbon::now()->toDateString(),
            'keterangan' => $validated['keterangan'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Data harga perkiraan berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    /**
     * Perbarui data harga perkiraan (sesuai form Ubah Data Harga Perkiraan).
     */
    public function update(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = HargaPerkiraan::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'harga_perkiraan' => ['required', 'numeric', 'min:0'],
            'tanggal_update' => ['nullable', 'date'],
            'kode_item' => ['sometimes', 'string', 'max:100'],
            'group_item' => ['sometimes', 'string', 'max:100'],
            'jenis_item' => ['sometimes', 'string', 'max:100'],
            'nama_item' => ['sometimes', 'string', 'max:255'],
            'satuan' => ['sometimes', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string'],
        ]);

        $updateData = [
            'harga_perkiraan' => $validated['harga_perkiraan'],
            'tanggal_update' => $validated['tanggal_update'] ?? Carbon::now()->toDateString(),
        ];

        if (isset($validated['kode_item'])) {
            $updateData['kode_item'] = $validated['kode_item'];
        }
        if (isset($validated['group_item'])) {
            $updateData['group_item'] = $validated['group_item'];
        }
        if (isset($validated['jenis_item'])) {
            $updateData['jenis_item'] = $validated['jenis_item'];
        }
        if (isset($validated['nama_item'])) {
            $updateData['nama_item'] = $validated['nama_item'];
        }
        if (isset($validated['satuan'])) {
            $updateData['satuan'] = $validated['satuan'];
        }
        if (array_key_exists('keterangan', $validated)) {
            $updateData['keterangan'] = $validated['keterangan'];
        }

        $record->update($updateData);

        return response()->json([
            'status' => 'success',
            'message' => 'Data harga perkiraan berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    /**
     * Hapus / arsipkan data harga perkiraan (soft delete).
     */
    public function destroy(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = HargaPerkiraan::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Data harga perkiraan berhasil diarsipkan.',
        ]);
    }
}
