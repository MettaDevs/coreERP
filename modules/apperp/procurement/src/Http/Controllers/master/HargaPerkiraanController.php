<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\HargaPerkiraan;

final class HargaPerkiraanController extends Controller
{
    /**
     * Pastikan tabel dan data acuan harga perkiraan (HPS) tersedia.
     */
    private function ensureTablesExist(string $tenantId): void
    {
        if (! Schema::hasTable('procurement_harga_perkiraan')) {
            Schema::create('procurement_harga_perkiraan', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('group_item', 100);
                $blueprint->string('jenis_item', 100);
                $blueprint->string('kode_item', 100);
                $blueprint->string('nama_item', 255);
                $blueprint->string('satuan', 50);
                $blueprint->decimal('harga_perkiraan', 18, 2)->default(0);
                $blueprint->date('tanggal_update')->nullable();
                $blueprint->text('keterangan')->nullable();
                $blueprint->softDeletes();
                $blueprint->timestamps();

                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'kode_item']);
                $blueprint->index(['tenant_id', 'group_item']);
                $blueprint->index(['tenant_id', 'jenis_item']);
            });
        }

        // Jika data kosong, sediakan 5 sampel item persis sesuai diagram acuan
        if (HargaPerkiraan::where('tenant_id', $tenantId)->count() === 0) {
            $samples = [
                [
                    'group_item' => 'Bahan',
                    'jenis_item' => 'Obat',
                    'kode_item' => 'Bahan 01',
                    'nama_item' => 'Obat A',
                    'satuan' => 'Tablet',
                    'harga_perkiraan' => 2000,
                    'tanggal_update' => '2026-08-10',
                ],
                [
                    'group_item' => 'Upah',
                    'jenis_item' => 'Perbaikan',
                    'kode_item' => 'Bahan 02',
                    'nama_item' => 'Obat B',
                    'satuan' => 'Botol',
                    'harga_perkiraan' => 5000,
                    'tanggal_update' => null,
                ],
                [
                    'group_item' => 'Asset',
                    'jenis_item' => 'Kendaraan',
                    'kode_item' => 'Bahan 03',
                    'nama_item' => 'BMHP1',
                    'satuan' => 'Pcs',
                    'harga_perkiraan' => 350,
                    'tanggal_update' => null,
                ],
                [
                    'group_item' => 'Subkont',
                    'jenis_item' => 'BMHP',
                    'kode_item' => 'Bahan 04',
                    'nama_item' => 'BMHP2',
                    'satuan' => 'Box',
                    'harga_perkiraan' => 10000,
                    'tanggal_update' => null,
                ],
                [
                    'group_item' => 'Overhead',
                    'jenis_item' => 'BMHP',
                    'kode_item' => 'Bahan 05',
                    'nama_item' => 'BMHP3',
                    'satuan' => 'Set',
                    'harga_perkiraan' => 50000,
                    'tanggal_update' => null,
                ],
            ];

            foreach ($samples as $sample) {
                HargaPerkiraan::create([
                    'tenant_id' => $tenantId,
                    'creation_key' => (string) Str::uuid(),
                    'group_item' => $sample['group_item'],
                    'jenis_item' => $sample['jenis_item'],
                    'kode_item' => $sample['kode_item'],
                    'nama_item' => $sample['nama_item'],
                    'satuan' => $sample['satuan'],
                    'harga_perkiraan' => $sample['harga_perkiraan'],
                    'tanggal_update' => $sample['tanggal_update'],
                ]);
            }
        }
    }

    /**
     * Daftar harga perkiraan (HPS) barang, upah, asset, subkont, dan overhead.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
        $this->ensureTablesExist($tenantId);

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
