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
use Modules\Apperp\Procurement\Models\master\KategoriPengadaan;

final class KategoriPengadaanController extends Controller
{
    /**
     * Pastikan tabel dan data acuan kategori pengadaan tersedia.
     */
    private function ensureTablesExist(string $tenantId): void
    {
        if (! Schema::hasTable('procurement_kategori_pengadaan')) {
            Schema::create('procurement_kategori_pengadaan', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('kode_pengadaan', 100);
                $blueprint->string('kategori_pengadaan', 255);
                $blueprint->text('keterangan')->nullable();
                $blueprint->boolean('aktif')->default(true);
                $blueprint->softDeletes();
                $blueprint->timestamps();

                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'kode_pengadaan']);
                $blueprint->index(['tenant_id', 'aktif']);
            });
        }

        // Jika data kosong, sediakan sampel acuan sesuai diagram persis
        if (KategoriPengadaan::where('tenant_id', $tenantId)->count() === 0) {
            $samples = [
                [
                    'kode_pengadaan' => 'Pengadaan.001',
                    'kategori_pengadaan' => 'Pengadaan Bahan',
                    'keterangan' => 'Kategori pengadaan untuk item bahan',
                    'aktif' => true,
                ],
                [
                    'kode_pengadaan' => 'Pengadaan.002',
                    'kategori_pengadaan' => 'Pengadaan Jasa & Upah',
                    'keterangan' => 'Kategori pengadaan jasa servis, pemeliharaan, dan tenaga kerja',
                    'aktif' => true,
                ],
                [
                    'kode_pengadaan' => 'Pengadaan.003',
                    'kategori_pengadaan' => 'Pengadaan Asset & Kendaraan',
                    'keterangan' => 'Kategori pengadaan aset tetap, inventaris, dan armada kendaraan',
                    'aktif' => true,
                ],
                [
                    'kode_pengadaan' => 'Pengadaan.004',
                    'kategori_pengadaan' => 'Pengadaan Subkontraktor',
                    'keterangan' => 'Kategori pengadaan paket pekerjaan subkontraktor spesialis',
                    'aktif' => true,
                ],
                [
                    'kode_pengadaan' => 'Pengadaan.005',
                    'kategori_pengadaan' => 'Pengadaan Overhead & Operasional',
                    'keterangan' => 'Kategori pengadaan biaya umum, operasional, dan perlengkapan kantor',
                    'aktif' => true,
                ],
            ];

            foreach ($samples as $sample) {
                KategoriPengadaan::create([
                    'tenant_id' => $tenantId,
                    'creation_key' => (string) Str::uuid(),
                    'kode_pengadaan' => $sample['kode_pengadaan'],
                    'kategori_pengadaan' => $sample['kategori_pengadaan'],
                    'keterangan' => $sample['keterangan'],
                    'aktif' => $sample['aktif'],
                ]);
            }
        }
    }

    /**
     * Tampilkan daftar seluruh kategori pengadaan.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
