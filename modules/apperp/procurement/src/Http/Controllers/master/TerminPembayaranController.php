<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\TerminPembayaran;

final class TerminPembayaranController extends Controller
{
    /**
     * Data standar termin pembayaran sesuai acuan wireframe.
     */
    public const DEFAULT_ITEMS = [
        [
            'kode_termin' => 'Termin.001',
            'nama_termin_bayar' => 'Down Payment (DP)',
            'keterangan' => 'Pembayaran sebagian di awal, sisanya setelah barang/jasa diterima',
            'aktif' => true,
        ],
    ];

    /**
     * Tampilkan daftar seluruh termin pembayaran.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        // Inisialisasi data default jika belum ada
        if (TerminPembayaran::where('tenant_id', $tenantId)->count() === 0) {
            foreach (self::DEFAULT_ITEMS as $default) {
                try {
                    TerminPembayaran::create([
                        'tenant_id' => $tenantId,
                        'creation_key' => (string) Str::uuid(),
                        'kode_termin' => $default['kode_termin'],
                        'nama_termin_bayar' => $default['nama_termin_bayar'],
                        'keterangan' => $default['keterangan'],
                        'aktif' => $default['aktif'],
                    ]);
                } catch (\Throwable) {
                }
            }
        }

        $query = TerminPembayaran::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('kode_termin');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('kode_termin', 'like', "%{$search}%")
                    ->orWhere('nama_termin_bayar', 'like', "%{$search}%")
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
                    'id' => sprintf('01JKTP0000000000000000000%d', $idx + 1),
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
     * Tampilkan detail satu termin pembayaran.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = TerminPembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /**
     * Tambah data termin pembayaran baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $validated = $request->validate([
            'kode_termin' => ['required', 'string', 'max:100'],
            'nama_termin_bayar' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Cek duplikasi kode termin dalam tenant
        $exists = TerminPembayaran::where('tenant_id', $tenantId)
            ->where('kode_termin', $validated['kode_termin'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode termin '.$validated['kode_termin'].' sudah terdaftar.',
                'errors' => [
                    'kode_termin' => ['Kode termin ini sudah digunakan.'],
                ],
            ], 422);
        }

        $record = TerminPembayaran::create([
            'tenant_id' => $tenantId,
            'creation_key' => (string) Str::uuid(),
            'kode_termin' => $validated['kode_termin'],
            'nama_termin_bayar' => $validated['nama_termin_bayar'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Termin pembayaran berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    /**
     * Perbarui data termin pembayaran.
     */
    public function update(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = TerminPembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'kode_termin' => ['required', 'string', 'max:100'],
            'nama_termin_bayar' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Pastikan kode unik selain dirinya sendiri
        $duplicate = TerminPembayaran::where('tenant_id', $tenantId)
            ->where('kode_termin', $validated['kode_termin'])
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode termin '.$validated['kode_termin'].' sudah terdaftar.',
                'errors' => [
                    'kode_termin' => ['Kode termin ini sudah digunakan oleh data lain.'],
                ],
            ], 422);
        }

        $record->update([
            'kode_termin' => $validated['kode_termin'],
            'nama_termin_bayar' => $validated['nama_termin_bayar'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? $record->aktif,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Termin pembayaran berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    /**
     * Toggle status aktif/non aktif termin pembayaran.
     */
    public function toggleStatus(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = TerminPembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->aktif = ! $record->aktif;
        $record->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status termin pembayaran berhasil diubah menjadi '.($record->aktif ? 'Aktif' : 'Non Aktif').'.',
            'data' => $record,
        ]);
    }

    /**
     * Hapus / arsipkan termin pembayaran (soft delete).
     */
    public function destroy(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = TerminPembayaran::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Termin pembayaran berhasil diarsipkan.',
        ]);
    }
}
