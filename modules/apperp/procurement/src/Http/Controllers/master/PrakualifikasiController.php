<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Apperp\Procurement\Models\master\Prakualifikasi;
use Modules\Apperp\Procurement\Models\master\PrakualifikasiDokumen;
use Modules\Apperp\Procurement\Models\master\PrakualifikasiItem;
use Throwable;

final class PrakualifikasiController extends Controller
{
    /**
     * Dokumen wajib standar sesuai acuan evaluasi kualifikasi mockup.
     *
     * @var list<string>
     */
    public const STANDARD_DOCUMENTS = [
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

    public const DOKUMEN_STANDAR = self::STANDARD_DOCUMENTS;

    /**
     * Daftar seluruh prakualifikasi dengan pencarian, filter, dan pagination.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $query = Prakualifikasi::query()
            ->where('tenant_id', $tenantId)
            ->with(['documents', 'items'])
            ->orderByDesc('tanggal_prakualifikasi')
            ->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('nomor_prakualifikasi', 'like', "%{$search}%")
                    ->orWhere('kode_vendor', 'like', "%{$search}%")
                    ->orWhere('nama_vendor', 'like', "%{$search}%")
                    ->orWhere('nama_pic', 'like', "%{$search}%")
                    ->orWhere('alamat', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status_kualifikasi')) {
            $query->where('status_kualifikasi', $status);
        }

        if ($statusVendor = $request->input('status_vendor')) {
            $query->where('status_vendor', $statusVendor);
        }

        if ($startDate = $request->input('start_date')) {
            $query->whereDate('tanggal_prakualifikasi', '>=', $startDate);
        }

        if ($endDate = $request->input('end_date')) {
            $query->whereDate('tanggal_prakualifikasi', '<=', $endDate);
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
     * Detail satu data prakualifikasi beserta dokumen dan item.
     */
    public function show(Request $request, TenantContext $context, string $id): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = Prakualifikasi::where('tenant_id', $tenantId)
            ->with(['documents', 'items'])
            ->find($id);

        if (! $record) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data prakualifikasi tidak ditemukan.',
            ], 404);
        }

        $result = $record->toArray();
        $result['dokumen'] = $result['documents'] ?? [];

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Simpan data prakualifikasi baru.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $validated = $request->validate([
            'tanggal_prakualifikasi' => 'required|date',
            'nomor_prakualifikasi' => 'nullable|string|max:100',
            'vendor_id' => 'nullable|string|max:36',
            'kode_vendor' => 'required|string|max:100',
            'nama_vendor' => 'required|string|max:255',
            'jenis_vendor' => 'nullable|string|max:100',
            'nama_pic' => 'nullable|string|max:150',
            'npwp_ktp' => 'nullable|string|max:100',
            'telepon' => 'nullable|string|max:50',
            'email' => 'nullable|string|max:150',
            'media_sosial' => 'nullable|string|max:150',
            'alamat' => 'nullable|string',
            'status_vendor' => 'nullable|string|max:100',
            'status_badan_hukum' => 'nullable|string|max:50',
            'status_kualifikasi' => 'nullable|string|max:50',
            'waktu_kesediaan_item' => 'nullable|string|max:100',
            'jumlah_karyawan' => 'nullable|integer',
            'catatan' => 'nullable|string',
            'dokumen' => 'nullable|array',
            'dokumen.*.nama_dokumen' => 'required|string|max:255',
            'dokumen.*.kategori' => 'nullable|string|max:50',
            'dokumen.*.ada' => 'nullable|boolean',
            'dokumen.*.keterangan' => 'nullable|string|max:255',
            'items' => 'nullable|array',
            'items.*.jenis_item' => 'nullable|string|max:100',
            'items.*.kode_item' => 'nullable|string|max:100',
            'items.*.nama_item' => 'required|string|max:255',
            'items.*.satuan' => 'nullable|string|max:50',
            'items.*.kesediaan' => 'nullable|string|max:100',
            'items.*.keterangan' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $tenantId): JsonResponse {
            $qualificationNumber = trim((string) ($validated['nomor_prakualifikasi'] ?? ''));

            if (empty($qualificationNumber)) {
                $qualificationNumber = $this->generateNextNumber($tenantId, (string) $validated['tanggal_prakualifikasi']);
            } else {
                $existing = Prakualifikasi::where('tenant_id', $tenantId)
                    ->where('nomor_prakualifikasi', $qualificationNumber)
                    ->first();

                if ($existing) {
                    if ($existing->kode_vendor === $validated['kode_vendor']) {
                        return $this->performUpdate($existing, $validated, $tenantId);
                    }
                    $qualificationNumber = $this->generateNextNumber($tenantId, (string) $validated['tanggal_prakualifikasi']);
                }
            }

            $prakualifikasi = Prakualifikasi::create([
                'tenant_id' => $tenantId,
                'creation_key' => (string) Str::uuid(),
                'nomor_prakualifikasi' => $qualificationNumber,
                'tanggal_prakualifikasi' => $validated['tanggal_prakualifikasi'],
                'vendor_id' => $validated['vendor_id'] ?? null,
                'kode_vendor' => $validated['kode_vendor'],
                'nama_vendor' => $validated['nama_vendor'],
                'jenis_vendor' => $validated['jenis_vendor'] ?? 'Distributor Utama',
                'nama_pic' => $validated['nama_pic'] ?? null,
                'npwp_ktp' => $validated['npwp_ktp'] ?? null,
                'telepon' => $validated['telepon'] ?? null,
                'email' => $validated['email'] ?? null,
                'media_sosial' => $validated['media_sosial'] ?? null,
                'alamat' => $validated['alamat'] ?? null,
                'status_vendor' => $validated['status_vendor'] ?? 'Vendor Resmi',
                'status_badan_hukum' => $validated['status_badan_hukum'] ?? 'Badan Hukum',
                'status_kualifikasi' => $validated['status_kualifikasi'] ?? 'Lolos',
                'waktu_kesediaan_item' => $validated['waktu_kesediaan_item'] ?? '7 Hari',
                'jumlah_karyawan' => $validated['jumlah_karyawan'] ?? null,
                'catatan' => $validated['catatan'] ?? null,
            ]);

            $documentsInput = $validated['dokumen'] ?? [];
            if (empty($documentsInput)) {
                $checkedIndices = [0, 4, 5, 6, 7, 8, 9];
                foreach (self::STANDARD_DOCUMENTS as $index => $docName) {
                    PrakualifikasiDokumen::create([
                        'tenant_id' => $tenantId,
                        'prakualifikasi_id' => $prakualifikasi->id,
                        'kategori' => 'wajib',
                        'urutan' => $index + 1,
                        'nama_dokumen' => $docName,
                        'ada' => in_array($index, $checkedIndices, true),
                        'keterangan' => null,
                    ]);
                }
            } else {
                foreach ($documentsInput as $index => $doc) {
                    PrakualifikasiDokumen::create([
                        'tenant_id' => $tenantId,
                        'prakualifikasi_id' => $prakualifikasi->id,
                        'kategori' => $doc['kategori'] ?? 'wajib',
                        'urutan' => $index + 1,
                        'nama_dokumen' => $doc['nama_dokumen'],
                        'ada' => (bool) ($doc['ada'] ?? false),
                        'keterangan' => $doc['keterangan'] ?? null,
                    ]);
                }
            }

            $itemsInput = $validated['items'] ?? [];
            foreach ($itemsInput as $index => $item) {
                PrakualifikasiItem::create([
                    'tenant_id' => $tenantId,
                    'prakualifikasi_id' => $prakualifikasi->id,
                    'urutan' => $index + 1,
                    'jenis_item' => $item['jenis_item'] ?? null,
                    'kode_item' => $item['kode_item'] ?? null,
                    'nama_item' => $item['nama_item'],
                    'satuan' => $item['satuan'] ?? null,
                    'kesediaan' => $item['kesediaan'] ?? 'Tersedia',
                    'keterangan' => $item['keterangan'] ?? null,
                ]);
            }

            $prakualifikasi->load(['documents', 'items']);
            $result = $prakualifikasi->toArray();
            $result['dokumen'] = $result['documents'] ?? [];

            return response()->json([
                'status' => 'success',
                'message' => 'Data prakualifikasi berhasil disimpan.',
                'data' => $result,
            ], 201);
        });
    }

    /**
     * Perbarui data prakualifikasi.
     */
    public function update(Request $request, TenantContext $context, string $id): JsonResponse
    {
        $tenantId = $context->tenantId();

        $prakualifikasi = Prakualifikasi::where('tenant_id', $tenantId)->find($id);

        if (! $prakualifikasi) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data prakualifikasi tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'tanggal_prakualifikasi' => 'required|date',
            'nomor_prakualifikasi' => 'nullable|string|max:100',
            'vendor_id' => 'nullable|string|max:36',
            'kode_vendor' => 'required|string|max:100',
            'nama_vendor' => 'required|string|max:255',
            'jenis_vendor' => 'nullable|string|max:100',
            'nama_pic' => 'nullable|string|max:150',
            'npwp_ktp' => 'nullable|string|max:100',
            'telepon' => 'nullable|string|max:50',
            'email' => 'nullable|string|max:150',
            'media_sosial' => 'nullable|string|max:150',
            'alamat' => 'nullable|string',
            'status_vendor' => 'nullable|string|max:100',
            'status_badan_hukum' => 'nullable|string|max:50',
            'status_kualifikasi' => 'nullable|string|max:50',
            'waktu_kesediaan_item' => 'nullable|string|max:100',
            'jumlah_karyawan' => 'nullable|integer',
            'catatan' => 'nullable|string',
            'dokumen' => 'nullable|array',
            'dokumen.*.nama_dokumen' => 'required|string|max:255',
            'dokumen.*.kategori' => 'nullable|string|max:50',
            'dokumen.*.ada' => 'nullable|boolean',
            'dokumen.*.keterangan' => 'nullable|string|max:255',
            'items' => 'nullable|array',
            'items.*.jenis_item' => 'nullable|string|max:100',
            'items.*.kode_item' => 'nullable|string|max:100',
            'items.*.nama_item' => 'required|string|max:255',
            'items.*.satuan' => 'nullable|string|max:50',
            'items.*.kesediaan' => 'nullable|string|max:100',
            'items.*.keterangan' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($prakualifikasi, $validated, $tenantId): JsonResponse {
            return $this->performUpdate($prakualifikasi, $validated, $tenantId);
        });
    }

    /**
     * Eksekusi update data induk, dokumen, dan item.
     *
     * @param  array<string, mixed>  $validated
     */
    private function performUpdate(Prakualifikasi $prakualifikasi, array $validated, string $tenantId): JsonResponse
    {
        $nomor = ! empty($validated['nomor_prakualifikasi'])
            ? trim((string) $validated['nomor_prakualifikasi'])
            : $prakualifikasi->nomor_prakualifikasi;

        $prakualifikasi->update([
            'tanggal_prakualifikasi' => $validated['tanggal_prakualifikasi'],
            'nomor_prakualifikasi' => $nomor,
            'vendor_id' => $validated['vendor_id'] ?? $prakualifikasi->vendor_id,
            'kode_vendor' => $validated['kode_vendor'],
            'nama_vendor' => $validated['nama_vendor'],
            'jenis_vendor' => $validated['jenis_vendor'] ?? $prakualifikasi->jenis_vendor,
            'nama_pic' => $validated['nama_pic'] ?? null,
            'npwp_ktp' => $validated['npwp_ktp'] ?? null,
            'telepon' => $validated['telepon'] ?? null,
            'email' => $validated['email'] ?? null,
            'media_sosial' => $validated['media_sosial'] ?? null,
            'alamat' => $validated['alamat'] ?? null,
            'status_vendor' => $validated['status_vendor'] ?? $prakualifikasi->status_vendor,
            'status_badan_hukum' => $validated['status_badan_hukum'] ?? $prakualifikasi->status_badan_hukum,
            'status_kualifikasi' => $validated['status_kualifikasi'] ?? $prakualifikasi->status_kualifikasi,
            'waktu_kesediaan_item' => $validated['waktu_kesediaan_item'] ?? $prakualifikasi->waktu_kesediaan_item,
            'jumlah_karyawan' => $validated['jumlah_karyawan'] ?? null,
            'catatan' => $validated['catatan'] ?? null,
        ]);

        if (isset($validated['dokumen'])) {
            PrakualifikasiDokumen::where('tenant_id', $tenantId)
                ->where('prakualifikasi_id', $prakualifikasi->id)
                ->delete();

            foreach ($validated['dokumen'] as $index => $doc) {
                PrakualifikasiDokumen::create([
                    'tenant_id' => $tenantId,
                    'prakualifikasi_id' => $prakualifikasi->id,
                    'kategori' => $doc['kategori'] ?? 'wajib',
                    'urutan' => $index + 1,
                    'nama_dokumen' => $doc['nama_dokumen'],
                    'ada' => (bool) ($doc['ada'] ?? false),
                    'keterangan' => $doc['keterangan'] ?? null,
                ]);
            }
        }

        if (isset($validated['items'])) {
            PrakualifikasiItem::where('tenant_id', $tenantId)
                ->where('prakualifikasi_id', $prakualifikasi->id)
                ->delete();

            foreach ($validated['items'] as $index => $item) {
                PrakualifikasiItem::create([
                    'tenant_id' => $tenantId,
                    'prakualifikasi_id' => $prakualifikasi->id,
                    'urutan' => $index + 1,
                    'jenis_item' => $item['jenis_item'] ?? null,
                    'kode_item' => $item['kode_item'] ?? null,
                    'nama_item' => $item['nama_item'],
                    'satuan' => $item['satuan'] ?? null,
                    'kesediaan' => $item['kesediaan'] ?? 'Tersedia',
                    'keterangan' => $item['keterangan'] ?? null,
                ]);
            }
        }

        $prakualifikasi->load(['documents', 'items']);
        $result = $prakualifikasi->toArray();
        $result['dokumen'] = $result['documents'] ?? [];

        return response()->json([
            'status' => 'success',
            'message' => 'Data prakualifikasi berhasil diperbarui.',
            'data' => $result,
        ]);
    }

    /**
     * Otomatis terbitkan nomor urut berikutnya tanpa bentrok.
     */
    private function generateNextNumber(string $tenantId, string $date): string
    {
        $yearShort = date('y', strtotime($date));
        $romanMonth = $this->toRoman((int) date('m', strtotime($date)));
        $count = Prakualifikasi::where('tenant_id', $tenantId)
            ->whereYear('tanggal_prakualifikasi', date('Y', strtotime($date)))
            ->count() + 1;
        $candidate = sprintf('Pra/%s/%s/%04d', $romanMonth, $yearShort, $count);

        while (Prakualifikasi::where('tenant_id', $tenantId)->where('nomor_prakualifikasi', $candidate)->exists()) {
            $count++;
            $candidate = sprintf('Pra/%s/%s/%04d', $romanMonth, $yearShort, $count);
        }

        return $candidate;
    }

    /**
     * Hapus (arsip soft delete) data prakualifikasi.
     */
    public function destroy(Request $request, TenantContext $context, string $id): JsonResponse
    {
        $tenantId = $context->tenantId();

        $prakualifikasi = Prakualifikasi::where('tenant_id', $tenantId)->find($id);

        if (! $prakualifikasi) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data prakualifikasi tidak ditemukan.',
            ], 404);
        }

        $prakualifikasi->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Data prakualifikasi berhasil diarsipkan.',
        ]);
    }

    /**
     * Lookup pencarian vendor untuk modal "Cari Data".
     */
    public function lookupVendors(Request $request, TenantContext $context): JsonResponse
    {
        $search = (string) $request->input('search', '');
        $results = [];

        try {
            $tenantId = $context->tenantId();

            $vendors = Prakualifikasi::query()
                ->where('tenant_id', $tenantId)
                ->when($search !== '', function ($q) use ($search): void {
                    $q->where(function ($sub) use ($search): void {
                        $sub->where('nama_vendor', 'like', "%{$search}%")
                            ->orWhere('kode_vendor', 'like', "%{$search}%");
                    });
                })
                ->take(30)
                ->get();

            foreach ($vendors as $v) {
                $results[] = [
                    'vendor_id' => (string) ($v->vendor_id ?: $v->id),
                    'kode_vendor' => (string) $v->kode_vendor,
                    'nama_vendor' => (string) $v->nama_vendor,
                    'alamat' => (string) ($v->alamat ?? '-'),
                    'telepon' => (string) ($v->telepon ?? '-'),
                    'email' => (string) ($v->email ?? '-'),
                    'nama_pic' => (string) ($v->nama_pic ?? '-'),
                    'jenis_vendor' => (string) ($v->jenis_vendor ?? 'Supplier'),
                    'status_vendor' => (string) ($v->status_vendor ?? 'Vendor Resmi'),
                    'status_badan_hukum' => (string) ($v->status_badan_hukum ?? 'Badan Hukum'),
                ];
            }
        } catch (Throwable) {
            // Silently fallback jika belum ada data di database
        }

        if (empty($results)) {
            $defaultVendors = [
                [
                    'vendor_id' => '01JPRK00000000000000000001',
                    'kode_vendor' => 'Supp-001',
                    'nama_vendor' => 'PT. ABCD',
                    'alamat' => 'Jln. Kusuma Bangsa II, No. 11',
                    'telepon' => '0361 - xxxxxxx',
                    'email' => 'contact@pt-abcd.co.id',
                    'nama_pic' => 'Bpk. A',
                    'jenis_vendor' => 'Distributor Utama',
                    'status_vendor' => 'Vendor Resmi',
                    'status_badan_hukum' => 'Badan Hukum',
                    'items' => [
                        ['jenis_item' => 'Obat', 'kode_item' => 'Bahan 001', 'nama_item' => 'Obat A', 'satuan' => 'Tablet', 'kesediaan' => '1.000'],
                        ['jenis_item' => 'Obat', 'kode_item' => 'Bahan 02', 'nama_item' => 'Obat B', 'satuan' => 'Botol', 'kesediaan' => '2.000'],
                        ['jenis_item' => 'BMHP', 'kode_item' => 'Bahan 03', 'nama_item' => 'BMHP1', 'satuan' => 'Pcs', 'kesediaan' => '3.000'],
                        ['jenis_item' => 'BMHP', 'kode_item' => 'Bahan 04', 'nama_item' => 'BMHP2', 'satuan' => 'Box', 'kesediaan' => '200'],
                        ['jenis_item' => 'BMHP', 'kode_item' => 'Bahan 05', 'nama_item' => 'BMHP3', 'satuan' => 'Set', 'kesediaan' => '150'],
                    ],
                ],
                [
                    'vendor_id' => '01JPRK00000000000000000002',
                    'kode_vendor' => 'Supp-002',
                    'nama_vendor' => 'PT. XYZ',
                    'alamat' => 'Jln. XYZ',
                    'telepon' => '082-98765432',
                    'email' => 'sales@xyz-corp.com',
                    'nama_pic' => 'Hendra',
                    'jenis_vendor' => 'Pabrikan / Manufaktur',
                    'status_vendor' => 'Vendor Resmi',
                    'status_badan_hukum' => 'Badan Hukum',
                    'items' => [
                        ['jenis_item' => 'BMHP', 'kode_item' => 'BMHP-01', 'nama_item' => 'Masker Bedah 3-Ply', 'satuan' => 'Box', 'kesediaan' => '1.500'],
                        ['jenis_item' => 'BMHP', 'kode_item' => 'BMHP-02', 'nama_item' => 'Sarung Tangan Medis Latex', 'satuan' => 'Box', 'kesediaan' => '800'],
                    ],
                ],
                [
                    'vendor_id' => '01JPRK00000000000000000003',
                    'kode_vendor' => 'Supp-003',
                    'nama_vendor' => 'PT. JKL',
                    'alamat' => 'Jln. JKL',
                    'telepon' => '085-11223344',
                    'email' => 'info@jkl-logistics.id',
                    'nama_pic' => 'Siti',
                    'jenis_vendor' => 'Agen Tunggal',
                    'status_vendor' => 'Vendor Resmi',
                    'status_badan_hukum' => 'Badan Hukum',
                    'items' => [
                        ['jenis_item' => 'Reagen', 'kode_item' => 'RGN-01', 'nama_item' => 'Reagen Rapid Test', 'satuan' => 'Kit', 'kesediaan' => '500'],
                    ],
                ],
            ];

            $results = $defaultVendors;
        }

        if ($search !== '') {
            $searchLower = strtolower($search);
            $results = array_values(array_filter($results, function ($item) use ($searchLower): bool {
                return str_contains(strtolower($item['kode_vendor']), $searchLower)
                    || str_contains(strtolower($item['nama_vendor']), $searchLower)
                    || str_contains(strtolower($item['alamat']), $searchLower);
            }));
        }

        return response()->json([
            'status' => 'success',
            'data' => $results,
        ]);
    }

    /**
     * Konversi angka bulan ke format Romawi.
     */
    private function toRoman(int $month): string
    {
        $romans = [
            1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
            7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
        ];

        return $romans[$month] ?? 'I';
    }
}
