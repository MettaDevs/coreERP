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
use Modules\Apperp\Procurement\Models\master\MetodePembayaran;

final class MetodePembayaranController extends Controller
{
    /**
     * Pastikan tabel dan data acuan metode pembayaran tersedia.
     */
    private function ensureTablesExist(string $tenantId): void
    {
        if (! Schema::hasTable('procurement_metode_pembayaran')) {
            Schema::create('procurement_metode_pembayaran', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('kode_bayar', 100);
                $blueprint->string('nama_metode_bayar', 255);
                $blueprint->text('keterangan')->nullable();
                $blueprint->boolean('aktif')->default(true);
                $blueprint->softDeletes();
                $blueprint->timestamps();

                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'kode_bayar']);
                $blueprint->index(['tenant_id', 'aktif']);
            });
        }

        // Jika data kosong, sediakan sampel acuan sesuai diagram persis
        if (MetodePembayaran::where('tenant_id', $tenantId)->count() === 0) {
            $samples = [
                [
                    'kode_bayar' => 'Metode.001',
                    'nama_metode_bayar' => 'Transfer Bank',
                    'keterangan' => 'Pembayaran melalui rekening bank',
                    'aktif' => true,
                ],
                [
                    'kode_bayar' => 'Metode.002',
                    'nama_metode_bayar' => 'Giro / Cek',
                    'keterangan' => 'Pembayaran menggunakan warkat kliring giro atau cek tunai',
                    'aktif' => true,
                ],
                [
                    'kode_bayar' => 'Metode.003',
                    'nama_metode_bayar' => 'Kas Kecil / Tunai',
                    'keterangan' => 'Pembayaran tunai langsung melalui kas operasional kantor',
                    'aktif' => true,
                ],
                [
                    'kode_bayar' => 'Metode.004',
                    'nama_metode_bayar' => 'Letter of Credit (L/C)',
                    'keterangan' => 'Pembayaran berdokumen L/C untuk pengadaan impor dan skala besar',
                    'aktif' => true,
                ],
                [
                    'kode_bayar' => 'Metode.005',
                    'nama_metode_bayar' => 'Virtual Account / VA',
                    'keterangan' => 'Pembayaran tagihan otomatis melalui nomor virtual account bank rekanan',
                    'aktif' => true,
                ],
            ];

            foreach ($samples as $sample) {
                MetodePembayaran::create([
                    'tenant_id' => $tenantId,
                    'creation_key' => (string) Str::uuid(),
                    'kode_bayar' => $sample['kode_bayar'],
                    'nama_metode_bayar' => $sample['nama_metode_bayar'],
                    'keterangan' => $sample['keterangan'],
                    'aktif' => $sample['aktif'],
                ]);
            }
        }
    }

    /**
     * Tampilkan daftar seluruh metode pembayaran.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

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
     * Tampilkan detail satu metode pembayaran.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
