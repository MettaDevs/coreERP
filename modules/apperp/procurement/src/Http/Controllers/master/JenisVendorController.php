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
use Modules\Apperp\Procurement\Models\master\JenisVendor;

final class JenisVendorController extends Controller
{
    /**
     * Pastikan tabel dan data acuan jenis vendor/supplier tersedia.
     */
    private function ensureTablesExist(string $tenantId): void
    {
        if (! Schema::hasTable('procurement_jenis_vendor')) {
            Schema::create('procurement_jenis_vendor', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('kode_jenis', 100);
                $blueprint->string('nama_jenis_vendor', 255);
                $blueprint->text('keterangan')->nullable();
                $blueprint->boolean('aktif')->default(true);
                $blueprint->softDeletes();
                $blueprint->timestamps();

                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'kode_jenis']);
                $blueprint->index(['tenant_id', 'aktif']);
            });
        }

        // Jika data kosong, sediakan sampel acuan sesuai diagram persis
        if (JenisVendor::where('tenant_id', $tenantId)->count() === 0) {
            $samples = [
                [
                    'kode_jenis' => 'Jenis.001',
                    'nama_jenis_vendor' => 'Supplier',
                    'keterangan' => 'Supplier obat dan alat medis',
                    'aktif' => true,
                ],
                [
                    'kode_jenis' => 'Jenis.002',
                    'nama_jenis_vendor' => 'Distributor Utama',
                    'keterangan' => 'Distributor resmi pemegang izin edar dan keagenan pabrikan',
                    'aktif' => true,
                ],
                [
                    'kode_jenis' => 'Jenis.003',
                    'nama_jenis_vendor' => 'Subkontraktor',
                    'keterangan' => 'Penyedia pekerjaan konstruksi, mekanikal elektrikal, dan spesialis',
                    'aktif' => true,
                ],
                [
                    'kode_jenis' => 'Jenis.004',
                    'nama_jenis_vendor' => 'Pabrikan / Prinsipal',
                    'keterangan' => 'Produsen manufaktur langsung barang material dan sarana prasarana',
                    'aktif' => true,
                ],
                [
                    'kode_jenis' => 'Jenis.005',
                    'nama_jenis_vendor' => 'Vendor Pemeliharaan & Servis',
                    'keterangan' => 'Penyedia jasa perawatan berkala, perbaikan alat, dan kalibrasi',
                    'aktif' => true,
                ],
            ];

            foreach ($samples as $sample) {
                JenisVendor::create([
                    'tenant_id' => $tenantId,
                    'creation_key' => (string) Str::uuid(),
                    'kode_jenis' => $sample['kode_jenis'],
                    'nama_jenis_vendor' => $sample['nama_jenis_vendor'],
                    'keterangan' => $sample['keterangan'],
                    'aktif' => $sample['aktif'],
                ]);
            }
        }
    }

    /**
     * Tampilkan daftar seluruh jenis vendor/supplier.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        $query = JenisVendor::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('kode_jenis');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('kode_jenis', 'like', "%{$search}%")
                    ->orWhere('nama_jenis_vendor', 'like', "%{$search}%")
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
     * Tampilkan detail satu jenis vendor.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        $record = JenisVendor::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /**
     * Tambah data jenis vendor baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        $validated = $request->validate([
            'kode_jenis' => ['required', 'string', 'max:100'],
            'nama_jenis_vendor' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Cek duplikasi kode jenis dalam tenant
        $exists = JenisVendor::where('tenant_id', $tenantId)
            ->where('kode_jenis', $validated['kode_jenis'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode jenis '.$validated['kode_jenis'].' sudah terdaftar.',
                'errors' => [
                    'kode_jenis' => ['Kode jenis ini sudah digunakan.'],
                ],
            ], 422);
        }

        $record = JenisVendor::create([
            'tenant_id' => $tenantId,
            'creation_key' => (string) Str::uuid(),
            'kode_jenis' => $validated['kode_jenis'],
            'nama_jenis_vendor' => $validated['nama_jenis_vendor'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis vendor berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    /**
     * Perbarui data jenis vendor.
     */
    public function update(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        $record = JenisVendor::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'kode_jenis' => ['required', 'string', 'max:100'],
            'nama_jenis_vendor' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Pastikan kode unik selain dirinya sendiri
        $duplicate = JenisVendor::where('tenant_id', $tenantId)
            ->where('kode_jenis', $validated['kode_jenis'])
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode jenis '.$validated['kode_jenis'].' sudah terdaftar.',
                'errors' => [
                    'kode_jenis' => ['Kode jenis ini sudah digunakan oleh data lain.'],
                ],
            ], 422);
        }

        $record->update([
            'kode_jenis' => $validated['kode_jenis'],
            'nama_jenis_vendor' => $validated['nama_jenis_vendor'],
            'keterangan' => $validated['keterangan'] ?? null,
            'aktif' => $validated['aktif'] ?? $record->aktif,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis vendor berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    /**
     * Toggle status aktif/non aktif jenis vendor.
     */
    public function toggleStatus(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        $record = JenisVendor::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->aktif = ! $record->aktif;
        $record->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status jenis vendor berhasil diubah menjadi '.($record->aktif ? 'Aktif' : 'Non Aktif').'.',
            'data' => $record,
        ]);
    }

    /**
     * Hapus / arsipkan jenis vendor (soft delete).
     */
    public function destroy(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        $record = JenisVendor::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis vendor berhasil diarsipkan.',
        ]);
    }
}
