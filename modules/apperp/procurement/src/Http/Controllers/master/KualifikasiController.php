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
use Modules\Apperp\Procurement\Models\master\Prakualifikasi;
use Modules\Apperp\Procurement\Models\master\PrakualifikasiDokumen;
use Modules\Apperp\Procurement\Models\master\PrakualifikasiItem;

final class KualifikasiController extends Controller
{
    /**
     * Pastikan tabel dan data acuan tersedia jika modul baru dipasang.
     */
    private function ensureTablesExist(string $tenantId): void
    {
        if (! Schema::hasTable('procurement_prakualifikasi')) {
            Schema::create('procurement_prakualifikasi', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('nomor_prakualifikasi', 100);
                $blueprint->date('tanggal_prakualifikasi');

                $blueprint->ulid('vendor_id')->nullable();
                $blueprint->string('kode_vendor', 100);
                $blueprint->string('nama_vendor', 255);
                $blueprint->string('jenis_vendor', 100)->nullable();
                $blueprint->string('nama_pic', 150)->nullable();
                $blueprint->string('npwp_ktp', 100)->nullable();
                $blueprint->string('telepon', 50)->nullable();
                $blueprint->string('email', 150)->nullable();
                $blueprint->string('media_sosial', 150)->nullable();
                $blueprint->text('alamat')->nullable();

                $blueprint->string('status_vendor', 100)->default('Vendor Resmi');
                $blueprint->string('status_badan_hukum', 50)->default('Badan Hukum');
                $blueprint->string('status_kualifikasi', 50)->default('Lolos');
                $blueprint->string('waktu_kesediaan_item', 100)->nullable();
                $blueprint->integer('jumlah_karyawan')->nullable();
                $blueprint->text('catatan')->nullable();

                $blueprint->softDeletes();
                $blueprint->timestamps();

                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'nomor_prakualifikasi']);
                $blueprint->index(['tenant_id', 'kode_vendor']);
                $blueprint->index(['tenant_id', 'status_kualifikasi']);
                $blueprint->index(['tenant_id', 'tanggal_prakualifikasi']);
            });
        }

        if (! Schema::hasTable('procurement_prakualifikasi_dokumen')) {
            Schema::create('procurement_prakualifikasi_dokumen', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->ulid('prakualifikasi_id');
                $blueprint->string('kategori', 50)->default('wajib');
                $blueprint->integer('urutan')->default(0);
                $blueprint->string('nama_dokumen', 255);
                $blueprint->boolean('ada')->default(false);
                $blueprint->string('keterangan', 255)->nullable();
                $blueprint->timestamps();

                $blueprint->foreign(['tenant_id', 'prakualifikasi_id'])
                    ->references(['tenant_id', 'id'])
                    ->on('procurement_prakualifikasi')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('procurement_prakualifikasi_item')) {
            Schema::create('procurement_prakualifikasi_item', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->ulid('prakualifikasi_id');
                $blueprint->integer('urutan')->default(0);
                $blueprint->string('jenis_item', 100)->nullable();
                $blueprint->string('kode_item', 100)->nullable();
                $blueprint->string('nama_item', 255);
                $blueprint->string('satuan', 50)->nullable();
                $blueprint->string('kesediaan', 100)->nullable();
                $blueprint->text('keterangan')->nullable();
                $blueprint->timestamps();

                $blueprint->foreign(['tenant_id', 'prakualifikasi_id'])
                    ->references(['tenant_id', 'id'])
                    ->on('procurement_prakualifikasi')
                    ->cascadeOnDelete();
            });
        }

        // Jika data kosong, sediakan sampel rekanan lolos kualifikasi sesuai contoh gambar
        if (Prakualifikasi::where('tenant_id', $tenantId)->count() === 0) {
            $sample = Prakualifikasi::create([
                'tenant_id' => $tenantId,
                'creation_key' => (string) Str::uuid(),
                'nomor_prakualifikasi' => 'Pra/VIII/26/0001',
                'tanggal_prakualifikasi' => '2026-08-05',
                'kode_vendor' => 'Supp-001',
                'nama_vendor' => 'PT. ABCD',
                'jenis_vendor' => 'Distributor Utama',
                'nama_pic' => 'Bpk. A',
                'npwp_ktp' => '01.234.567.8-901.000',
                'telepon' => '0361 - xxxxxxx',
                'email' => 'kontak@pt-abcd.co.id',
                'media_sosial' => 'www.pt-abcd.co.id',
                'alamat' => 'Jln. Kusuma Bangsa II, No. 11',
                'status_vendor' => 'Vendor Resmi',
                'status_badan_hukum' => 'Badan Hukum',
                'status_kualifikasi' => 'Lolos',
                'waktu_kesediaan_item' => '7 Hari',
                'jumlah_karyawan' => 45,
                'catatan' => 'Berkas kelengkapan legalitas dan referensi keagenan telah diverifikasi lengkap dan valid.',
            ]);

            $standardDocuments = [
                'Surat Penunjukan Keagenan',
                'Sertifikat Produk/Mutu',
                'Referensi Bank',
                'Kemampuan Modal Usaha',
                'Sertifikat',
                'Akte Pendirian',
                'NIB',
                'TDP (Tanda Daftar Perusahaan)',
                'SIUP',
                'SITU',
            ];

            $checkedIndices = [0, 4, 5, 6, 7, 8, 9];
            foreach ($standardDocuments as $index => $docName) {
                PrakualifikasiDokumen::create([
                    'tenant_id' => $tenantId,
                    'prakualifikasi_id' => $sample->id,
                    'kategori' => 'wajib',
                    'urutan' => $index + 1,
                    'nama_dokumen' => $docName,
                    'ada' => in_array($index, $checkedIndices, true),
                    'keterangan' => null,
                ]);
            }

            $sampleItems = [
                ['jenis_item' => 'Obat', 'kode_item' => 'Bahan 001', 'nama_item' => 'Obat A', 'satuan' => 'Tablet', 'kesediaan' => '1.000'],
                ['jenis_item' => 'Obat', 'kode_item' => 'Bahan 02', 'nama_item' => 'Obat B', 'satuan' => 'Botol', 'kesediaan' => '2.000'],
                ['jenis_item' => 'BMHP', 'kode_item' => 'Bahan 03', 'nama_item' => 'BMHP1', 'satuan' => 'Pcs', 'kesediaan' => '3.000'],
                ['jenis_item' => 'BMHP', 'kode_item' => 'Bahan 04', 'nama_item' => 'BMHP2', 'satuan' => 'Box', 'kesediaan' => '200'],
                ['jenis_item' => 'BMHP', 'kode_item' => 'Bahan 05', 'nama_item' => 'BMHP3', 'satuan' => 'Set', 'kesediaan' => '150'],
            ];

            foreach ($sampleItems as $index => $item) {
                PrakualifikasiItem::create([
                    'tenant_id' => $tenantId,
                    'prakualifikasi_id' => $sample->id,
                    'urutan' => $index + 1,
                    'jenis_item' => $item['jenis_item'],
                    'kode_item' => $item['kode_item'],
                    'nama_item' => $item['nama_item'],
                    'satuan' => $item['satuan'],
                    'kesediaan' => $item['kesediaan'],
                ]);
            }
        }
    }

    /**
     * Menampilkan daftar vendor yang telah lolos kualifikasi (Daftar Kualifikasi Vendor/Supplier).
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        // Kualifikasi vendor memfilter rekanan yang berstatus lolos evaluasi kualifikasi
        $query = Prakualifikasi::query()
            ->where('tenant_id', $tenantId)
            ->where('status_kualifikasi', 'Lolos')
            ->with(['documents', 'items'])
            ->orderByDesc('tanggal_prakualifikasi')
            ->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('nomor_prakualifikasi', 'like', "%{$search}%")
                    ->orWhere('kode_vendor', 'like', "%{$search}%")
                    ->orWhere('nama_vendor', 'like', "%{$search}%")
                    ->orWhere('nama_pic', 'like', "%{$search}%")
                    ->orWhere('telepon', 'like', "%{$search}%")
                    ->orWhere('alamat', 'like', "%{$search}%");
            });
        }

        if ($statusVendor = $request->input('status_vendor')) {
            $query->where('status_vendor', $statusVendor);
        }

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $query->paginate($perPage);

        $transformedItems = collect($paginated->items())->map(function (Prakualifikasi $record): array {
            $array = $record->toArray();
            $array['dokumen'] = $array['documents'] ?? [];
            return $array;
        });

        return response()->json([
            'status' => 'success',
            'data' => $transformedItems,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Menampilkan detail lengkap rekanan kualifikasi beserta dokumen dan item.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();
        $this->ensureTablesExist($tenantId);

        $record = Prakualifikasi::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['documents', 'items'])
            ->firstOrFail();

        $array = $record->toArray();
        $array['dokumen'] = $array['documents'] ?? [];

        return response()->json([
            'status' => 'success',
            'data' => $array,
        ]);
    }
}
