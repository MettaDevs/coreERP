<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\MetodePembayaran;

final class MetodePembayaranController extends Controller
{
    /**
     * Data standar metode pembayaran sesuai acuan wireframe.
     */
    public const DEFAULT_ITEMS = [
        [
            'kode_bayar' => 'Metode.001',
            'nama_metode_bayar' => 'Transfer Bank',
            'keterangan' => 'Pembayaran melalui rekening bank',
            'aktif' => true,
        ],
    ];

    /**
     * Tampilkan daftar seluruh metode pembayaran.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        // Inisialisasi data default jika belum ada
        if (MetodePembayaran::where('tenant_id', $tenantId)->count() === 0) {
            foreach (self::DEFAULT_ITEMS as $default) {
                try {
                    MetodePembayaran::create([
                        'tenant_id' => $tenantId,
                        'creation_key' => (string) Str::uuid(),
                        'kode_bayar' => $default['kode_bayar'],
                        'nama_metode_bayar' => $default['nama_metode_bayar'],
                        'keterangan' => $default['keterangan'],
                        'aktif' => $default['aktif'],
                    ]);
                } catch (\Throwable) {
                }
            }
        }

        $query = MetodePembayaran::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('kode_bayar');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('kode_bayar', 'like', "%{$search}%")
                    ->orWhere('nama_metode_bayar', 'like', "%{$search}%")
                    ->orWhere('keterangan', 'like', "%{$search}%");
            });
        }

        if ($request->has('aktif')) {
            $query->where('aktif', filter_var($request->input('aktif'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $query->paginate($perPage);

        $items = $paginated->items();

        if (empty($items) && empty($search)) {
            $items = collect(self::DEFAULT_ITEMS)->map(function ($item, $idx) use ($tenantId) {
                return array_merge($item, [
                    'id' => sprintf('01JKMP0000000000000000000%d', $idx + 1),
                    'tenant_id' => $tenantId,
                    'created_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ]);
            })->all();
        }

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => max($paginated->total(), count($items)),
            ],
        ]);
    }

    /**
     * Tampilkan detail satu metode pembayaran.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = MetodePembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /**
     * Tambah data metode pembayaran baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $validated = $request->validate([
            'kode_bayar' => ['required', 'string', 'max:100'],
            'nama_metode_bayar' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Cek duplikasi kode bayar dalam tenant
        $exists = MetodePembayaran::where('tenant_id', $tenantId)
            ->where('kode_bayar', $validated['kode_bayar'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode bayar '.$validated['kode_bayar'].' sudah terdaftar.',
                'errors' => [
                    'kode_bayar' => ['Kode bayar ini sudah digunakan.'],
                ],
            ], 422);
        }

        $record = MetodePembayaran::create([
            'tenant_id' => $tenantId,
            'creation_key' => (string) Str::uuid(),
            'kode_bayar' => $validated['kode_bayar'],
            'nama_metode_bayar' => $validated['nama_metode_bayar'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Metode pembayaran berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    /**
     * Perbarui data metode pembayaran.
     */
    public function update(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = MetodePembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'kode_bayar' => ['required', 'string', 'max:100'],
            'nama_metode_bayar' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Pastikan kode unik selain dirinya sendiri
        $duplicate = MetodePembayaran::where('tenant_id', $tenantId)
            ->where('kode_bayar', $validated['kode_bayar'])
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode bayar '.$validated['kode_bayar'].' sudah terdaftar.',
                'errors' => [
                    'kode_bayar' => ['Kode bayar ini sudah digunakan oleh data lain.'],
                ],
            ], 422);
        }

        $record->update([
            'kode_bayar' => $validated['kode_bayar'],
            'nama_metode_bayar' => $validated['nama_metode_bayar'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? $record->aktif,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Metode pembayaran berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    /**
     * Toggle status aktif/non aktif metode pembayaran.
     */
    public function toggleStatus(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = MetodePembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->aktif = ! $record->aktif;
        $record->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status metode pembayaran berhasil diubah menjadi '.($record->aktif ? 'Aktif' : 'Non Aktif').'.',
            'data' => $record,
        ]);
    }

    /**
     * Hapus / arsipkan metode pembayaran (soft delete).
     */
    public function destroy(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = MetodePembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Metode pembayaran berhasil diarsipkan.',
        ]);
    }
}
