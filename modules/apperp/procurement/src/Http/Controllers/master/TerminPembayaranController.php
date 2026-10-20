<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\TerminPembayaran;

final class TerminPembayaranController extends Controller
{
    /**
     * Pastikan tabel dan data acuan termin pembayaran tersedia.
     */
    private function ensureTablesExist(string $tenantId): void
    {
        if (! Schema::hasTable('procurement_termin_pembayaran')) {
            Schema::create('procurement_termin_pembayaran', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('kode_termin', 100);
                $blueprint->string('nama_termin_bayar', 255);
                $blueprint->text('keterangan')->nullable();
                $blueprint->boolean('aktif')->default(true);
                $blueprint->softDeletes();
                $blueprint->timestamps();

                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'kode_termin']);
                $blueprint->index(['tenant_id', 'aktif']);
            });
        }

        // Jika data kosong, sediakan sampel acuan sesuai diagram persis
        if (TerminPembayaran::where('tenant_id', $tenantId)->count() === 0) {
            $samples = [
                [
                    'kode_termin' => 'Termin.001',
                    'nama_termin_bayar' => 'Down Payment (DP)',
                    'keterangan' => 'Pembayaran sebagian di awal, sisanya setelah barang/jasa diterima',
                    'aktif' => true,
                ],
                [
                    'kode_termin' => 'Termin.002',
                    'nama_termin_bayar' => 'Cash on Delivery (COD)',
                    'keterangan' => 'Pembayaran lunas saat barang atau hasil pekerjaan telah diterima di tempat',
                    'aktif' => true,
                ],
                [
                    'kode_termin' => 'Termin.003',
                    'nama_termin_bayar' => 'Net 30 Hari',
                    'keterangan' => 'Jatuh tempo pembayaran penuh 30 hari kalender setelah faktur diterbitkan',
                    'aktif' => true,
                ],
                [
                    'kode_termin' => 'Termin.004',
                    'nama_termin_bayar' => 'Net 14 Hari',
                    'keterangan' => 'Jatuh tempo pembayaran 14 hari kalender setelah faktur diterima dan diverifikasi',
                    'aktif' => true,
                ],
                [
                    'kode_termin' => 'Termin.005',
                    'nama_termin_bayar' => 'Bertahap Sesuai Progress (Milestone)',
                    'keterangan' => 'Pembayaran diangsur bertahap sesuai persentase kemajuan penyelesaian pekerjaan',
                    'aktif' => true,
                ],
            ];

            foreach ($samples as $sample) {
                TerminPembayaran::create([
                    'tenant_id' => $tenantId,
                    'creation_key' => (string) Str::uuid(),
                    'kode_termin' => $sample['kode_termin'],
                    'nama_termin_bayar' => $sample['nama_termin_bayar'],
                    'keterangan' => $sample['keterangan'],
                    'aktif' => $sample['aktif'],
                ]);
            }
        }
    }

    /**
     * Tampilkan daftar seluruh termin pembayaran.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

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
     * Tampilkan detail satu termin pembayaran.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
