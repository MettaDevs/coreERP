<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\Master;

use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\Procurement\Models\Master\VendorPrequalification;
use Modules\Apperp\Procurement\Models\Master\VendorPrequalificationDocument;
use Modules\Apperp\Procurement\Models\Master\VendorPrequalificationItem;

final class VendorPrequalificationController extends Controller
{
    private const APP_ID = 'procurement';

    private const RESOURCE = 'prakualifikasi';

    private const NUMBER_REFERENCE = 'procurement.prakualifikasi';

    /**
     * Daftar dokumen wajib standar untuk evaluasi prakualifikasi vendor.
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

    /**
     * Daftar status evaluasi prakualifikasi yang diizinkan.
     *
     * @var list<string>
     */
    public const QUALIFICATION_STATUSES = [
        'Dalam Proses',
        'Lolos',
        'Tidak Lolos',
    ];

    /**
     * Daftar prakualifikasi secara bertahap (cursor/incremental) tanpa memuat rincian child.
     */
    public function index(Request $request, RequestContext $context, VendorDirectory $vendors): JsonResponse
    {
        $context->requirePermission('procurement.prakualifikasi.read');
        $tenantId = $context->tenantId();

        $filters = $request->validate([
            'status_kualifikasi' => ['nullable', 'string', Rule::in(self::QUALIFICATION_STATUSES)],
            'status_vendor' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = VendorPrequalification::query()
            ->where('tenant_id', $tenantId)
            ->select([
                'id',
                'tenant_id',
                'nomor_prakualifikasi',
                'tanggal_prakualifikasi',
                'vendor_id',
                'kode_vendor',
                'nama_vendor',
                'jenis_vendor',
                'nama_pic',
                'telepon',
                'alamat',
                'status_vendor',
                'status_badan_hukum',
                'status_kualifikasi',
                'version',
                'created_at',
                'updated_at',
            ]);

        if (($filters['status_kualifikasi'] ?? '') !== '' && ($filters['status_kualifikasi'] ?? null) !== null) {
            $query->where('status_kualifikasi', $filters['status_kualifikasi']);
        }

        if (($filters['status_vendor'] ?? '') !== '' && ($filters['status_vendor'] ?? null) !== null) {
            $query->where('status_vendor', $filters['status_vendor']);
        }

        if (($filters['start_date'] ?? '') !== '' && ($filters['start_date'] ?? null) !== null) {
            $query->whereDate('tanggal_prakualifikasi', '>=', $filters['start_date']);
        }

        if (($filters['end_date'] ?? '') !== '' && ($filters['end_date'] ?? null) !== null) {
            $query->whereDate('tanggal_prakualifikasi', '<=', $filters['end_date']);
        }

        $batch = IncrementalList::query(
            $request,
            $query->toBase(),
            [
                'id' => 'id',
                'tanggal_prakualifikasi' => 'tanggal_prakualifikasi',
                'nomor_prakualifikasi' => 'nomor_prakualifikasi',
                'kode_vendor' => 'kode_vendor',
                'nama_vendor' => 'nama_vendor',
                'status_kualifikasi' => 'status_kualifikasi',
            ],
            [
                'nomor_prakualifikasi',
                'kode_vendor',
                'nama_vendor',
                'nama_pic',
                'alamat',
            ],
            defaultSort: 'tanggal_prakualifikasi',
            defaultDirection: 'desc',
        );

        $vendorIds = array_values(
            $batch['data']
                ->pluck('vendor_id')
                ->filter()
                ->map(strval(...))
                ->unique()
                ->all(),
        );
        $vendorMap = $vendorIds === [] ? [] : $vendors->findMany($tenantId, $vendorIds);

        $batch['data'] = VendorPrequalification::hydrate(
            $batch['data']->map(static fn ($row): array => (array) $row)->all(),
        )->map(fn (VendorPrequalification $record): array => $this->presentSummary($record, $vendorMap))->values();

        return response()->json($batch);
    }

    /**
     * Kebijakan penomoran untuk form prakualifikasi.
     */
    public function numberPolicy(RequestContext $context, NumberSequenceIssuer $numbers): JsonResponse
    {
        $context->requirePermission('procurement.prakualifikasi.read');
        $tenantId = $context->tenantId();

        return response()->json([
            'data' => [
                'allow_manual' => $numbers->allowsManual($tenantId, self::APP_ID, self::NUMBER_REFERENCE),
                'allow_automatic' => $numbers->allowsAutomatic($tenantId, self::APP_ID, self::NUMBER_REFERENCE),
            ],
        ]);
    }

    /**
     * Lookup daftar vendor aktif milik perusahaan aktif dari Core VendorDirectory.
     */
    public function lookupVendors(Request $request, RequestContext $context, VendorDirectory $vendors): JsonResponse
    {
        $context->requirePermission('procurement.prakualifikasi.read');
        $tenantId = $context->tenantId();
        $legalEntityId = $this->requireLegalEntityId($request);

        return response()->json($vendors->lookup($tenantId, $legalEntityId, $request->query()));
    }

    /**
     * Detail satu prakualifikasi beserta dokumen dan item untuk card detail/edit.
     */
    public function show(RequestContext $context, VendorDirectory $vendors, string $id): JsonResponse
    {
        $context->requirePermission('procurement.prakualifikasi.read');
        $tenantId = $context->tenantId();

        $record = VendorPrequalification::query()
            ->where('tenant_id', $tenantId)
            ->with(['documents', 'items'])
            ->findOrFail($id);

        $vendor = $vendors->find($tenantId, $record->vendor_id);

        return response()->json(
            ['data' => $this->presentDetail($record, $vendor)],
            200,
            ['ETag' => RowVersion::etag($record->version)],
        );
    }

    /**
     * Simpan prakualifikasi baru secara idempotent dengan NumberSequenceIssuer Core.
     */
    public function store(
        Request $request,
        RequestContext $context,
        NumberSequenceIssuer $numbers,
        VendorDirectory $vendors,
    ): JsonResponse {
        $context->requirePermission('procurement.prakualifikasi.create');
        $tenantId = $context->tenantId();
        $legalEntityId = $this->requireLegalEntityId($request);

        $creationKey = (string) $request->header('Idempotency-Key');
        validator(
            ['key' => $creationKey],
            ['key' => ['required', 'string', 'max:133', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();

        $validated = $this->validatePayload($request);
        $vendor = $this->resolveVerifiedVendor($vendors, $tenantId, $legalEntityId, $validated['vendor_id'], requireActive: true);
        $manualNumber = $this->trimmedOrNull($validated['nomor_prakualifikasi'] ?? null);

        if ($manualNumber !== null) {
            $duplicateExists = VendorPrequalification::query()
                ->where('tenant_id', $tenantId)
                ->where('nomor_prakualifikasi', $manualNumber)
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'nomor_prakualifikasi' => 'Nomor prakualifikasi ini sudah digunakan oleh dokumen aktif lain.',
                ]);
            }
        }

        $headerPayload = $this->buildHeaderPayload($validated, $vendor);
        $documentsPayload = $this->normalizeDocumentsPayload($validated['documents'] ?? []);
        $itemsPayload = $this->normalizeItemsPayload($validated['items'] ?? []);
        $payloadHash = $this->computePayloadHash($headerPayload, $documentsPayload, $itemsPayload, $manualNumber);

        $existing = VendorPrequalification::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('creation_key', $creationKey)
            ->with(['documents', 'items'])
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $payloadHash, $vendors, $tenantId);
        }

        try {
            $record = DB::transaction(function () use (
                $numbers,
                $tenantId,
                $legalEntityId,
                $creationKey,
                $manualNumber,
                $headerPayload,
                $documentsPayload,
                $itemsPayload,
                $payloadHash,
            ): VendorPrequalification {
                $issued = $numbers->issue(
                    [
                        'tenant_id' => $tenantId,
                        'app_id' => self::APP_ID,
                        'legal_entity_id' => $legalEntityId,
                    ],
                    self::NUMBER_REFERENCE,
                    self::RESOURCE.':'.$creationKey,
                    $manualNumber,
                );

                $record = VendorPrequalification::query()->create([
                    'tenant_id' => $tenantId,
                    'creation_key' => $creationKey,
                    'creation_payload_hash' => $payloadHash,
                    'nomor_prakualifikasi' => $issued['number'],
                    ...$headerPayload,
                ]);

                $this->syncDocuments($record, $tenantId, $documentsPayload);
                $this->syncItems($record, $tenantId, $itemsPayload);

                return $record->refresh()->load(['documents', 'items']);
            });
        } catch (QueryException $exception) {
            $existing = VendorPrequalification::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('creation_key', $creationKey)
                ->with(['documents', 'items'])
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $payloadHash, $vendors, $tenantId);
            }

            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'procurement_prakualifikasi_nomor_unique')) {
                throw ValidationException::withMessages([
                    'nomor_prakualifikasi' => 'Nomor prakualifikasi ini sudah digunakan oleh dokumen aktif lain.',
                ]);
            }

            throw $exception;
        }

        return response()->json(
            ['data' => $this->presentDetail($record, $vendor)],
            201,
            [
                'Location' => $request->url().'/'.$record->getKey(),
                'ETag' => RowVersion::etag($record->version),
            ],
        );
    }

    /**
     * Perbarui data prakualifikasi dengan pengaman versi baris (RowVersion) dan soft-delete child.
     */
    public function update(
        Request $request,
        RequestContext $context,
        VendorDirectory $vendors,
        string $id,
    ): JsonResponse {
        $context->requirePermission('procurement.prakualifikasi.update');
        $tenantId = $context->tenantId();
        $legalEntityId = $this->requireLegalEntityId($request);
        $expectedVersion = RowVersion::expected($request);

        $validated = $this->validatePayload($request);

        return DB::transaction(function () use (
            $tenantId,
            $legalEntityId,
            $id,
            $expectedVersion,
            $validated,
            $vendors,
        ): JsonResponse {
            $record = VendorPrequalification::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($id);

            RowVersion::claim($record, $expectedVersion);

            $vendorChanged = $record->vendor_id !== $validated['vendor_id'];
            $vendor = $this->resolveVerifiedVendor(
                $vendors,
                $tenantId,
                $legalEntityId,
                $validated['vendor_id'],
                requireActive: $vendorChanged,
            );

            $manualNumber = $this->trimmedOrNull($validated['nomor_prakualifikasi'] ?? null);
            $nextNumber = $manualNumber ?? $record->nomor_prakualifikasi;

            if ($nextNumber !== $record->nomor_prakualifikasi) {
                $duplicateExists = VendorPrequalification::query()
                    ->where('tenant_id', $tenantId)
                    ->where('nomor_prakualifikasi', $nextNumber)
                    ->whereKeyNot($record->getKey())
                    ->exists();

                if ($duplicateExists) {
                    throw ValidationException::withMessages([
                        'nomor_prakualifikasi' => 'Nomor prakualifikasi ini sudah digunakan oleh dokumen aktif lain.',
                    ]);
                }
            }

            $headerPayload = $this->buildHeaderPayload($validated, $vendor);
            $documentsPayload = $this->normalizeDocumentsPayload($validated['documents'] ?? []);
            $itemsPayload = $this->normalizeItemsPayload($validated['items'] ?? []);

            $record->update([
                'nomor_prakualifikasi' => $nextNumber,
                ...$headerPayload,
            ]);

            $this->syncDocuments($record, $tenantId, $documentsPayload);
            $this->syncItems($record, $tenantId, $itemsPayload);

            $fresh = VendorPrequalification::query()
                ->where('tenant_id', $tenantId)
                ->with(['documents', 'items'])
                ->findOrFail($id);

            return response()->json(
                ['data' => $this->presentDetail($fresh, $vendor)],
                200,
                ['ETag' => RowVersion::etag($fresh->version)],
            );
        });
    }

    /**
     * Arsipkan (soft delete) data prakualifikasi dengan pengaman versi baris.
     */
    public function destroy(Request $request, RequestContext $context, string $id): JsonResponse
    {
        $context->requirePermission('procurement.prakualifikasi.archive');
        $tenantId = $context->tenantId();
        $expectedVersion = RowVersion::expected($request);

        return DB::transaction(function () use ($tenantId, $id, $expectedVersion): JsonResponse {
            $record = VendorPrequalification::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($id);

            RowVersion::claim($record, $expectedVersion);

            VendorPrequalificationDocument::query()
                ->where('tenant_id', $tenantId)
                ->where('prakualifikasi_id', $record->id)
                ->delete();

            VendorPrequalificationItem::query()
                ->where('tenant_id', $tenantId)
                ->where('prakualifikasi_id', $record->id)
                ->delete();

            $record->delete();

            return response()->json(status: 204);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $input = $request->all();

        // Kompatibilitas kunci `dokumen` maupun `documents` pada payload request
        if (! array_key_exists('documents', $input) && array_key_exists('dokumen', $input)) {
            $input['documents'] = $input['dokumen'];
        }

        $validated = validator($input, [
            'tanggal_prakualifikasi' => ['required', 'date_format:Y-m-d'],
            'nomor_prakualifikasi' => ['nullable', 'string', 'max:100'],
            'vendor_id' => ['required', 'ulid'],
            'jenis_vendor' => ['nullable', 'string', 'max:100'],
            'nama_pic' => ['nullable', 'string', 'max:150'],
            'npwp_ktp' => ['nullable', 'string', 'max:100'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'media_sosial' => ['nullable', 'string', 'max:150'],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'status_vendor' => ['nullable', 'string', 'max:100'],
            'status_badan_hukum' => ['required', 'string', 'max:50'],
            'status_kualifikasi' => ['nullable', 'string', Rule::in(self::QUALIFICATION_STATUSES)],
            'waktu_kesediaan_item' => ['nullable', 'string', 'max:100'],
            'jumlah_karyawan' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:100'],
            'documents.*.id' => ['nullable', 'ulid'],
            'documents.*.kategori' => ['required', 'string', Rule::in(['wajib', 'tambahan'])],
            'documents.*.nama_dokumen' => ['required', 'string', 'max:255'],
            'documents.*.ada' => ['required', 'boolean'],
            'documents.*.keterangan' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'ulid'],
            'items.*.jenis_item' => ['nullable', 'string', 'max:100'],
            'items.*.kode_item' => ['nullable', 'string', 'max:100'],
            'items.*.nama_item' => ['required', 'string', 'max:255'],
            'items.*.satuan' => ['nullable', 'string', 'max:50'],
            'items.*.kesediaan' => ['nullable', 'string', 'max:100'],
            'items.*.keterangan' => ['nullable', 'string', 'max:1000'],
        ], [
            'tanggal_prakualifikasi.required' => 'Tanggal prakualifikasi wajib diisi.',
            'tanggal_prakualifikasi.date_format' => 'Format tanggal prakualifikasi harus YYYY-MM-DD.',
            'vendor_id.required' => 'Pilih vendor rekanan terlebih dahulu.',
            'vendor_id.ulid' => 'Identitas vendor rekanan tidak valid.',
            'email.email' => 'Format alamat email tidak valid.',
            'status_badan_hukum.required' => 'Pilih status badan hukum rekanan.',
            'status_kualifikasi.in' => 'Pilih status evaluasi kualifikasi yang valid.',
            'documents.*.nama_dokumen.required' => 'Nama dokumen wajib diisi pada setiap baris dokumen.',
            'items.*.nama_item.required' => 'Nama item wajib diisi pada setiap baris item.',
        ])->validate();

        $validated['documents'] = array_values($validated['documents'] ?? []);
        $validated['items'] = array_values($validated['items'] ?? []);

        return $validated;
    }

    /**
     * Validasi bahwa vendor_id benar-benar ada di Core VendorDirectory pada tenant & perusahaan aktif.
     *
     * @return array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}
     */
    private function resolveVerifiedVendor(
        VendorDirectory $vendors,
        string $tenantId,
        string $legalEntityId,
        string $vendorId,
        bool $requireActive,
    ): array {
        $vendor = $vendors->find($tenantId, $vendorId);

        if ($vendor === null || $vendor['legal_entity_id'] !== $legalEntityId) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Pilih vendor rekanan dari daftar vendor perusahaan aktif.',
            ]);
        }

        if ($requireActive && $vendor['status'] !== 'active') {
            throw ValidationException::withMessages([
                'vendor_id' => 'Vendor yang dipilih sedang tidak aktif.',
            ]);
        }

        return $vendor;
    }

    private function requireLegalEntityId(Request $request): string
    {
        $legalEntityId = $this->trimmedOrNull($request->attributes->get('coreerp.legal_entity_id'));

        if ($legalEntityId === null) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Pilih perusahaan aktif terlebih dahulu sebelum mengelola prakualifikasi vendor.',
            ]);
        }

        return $legalEntityId;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}  $vendor
     * @return array<string, mixed>
     */
    private function buildHeaderPayload(array $validated, array $vendor): array
    {
        return [
            'tanggal_prakualifikasi' => $validated['tanggal_prakualifikasi'],
            'vendor_id' => $vendor['id'],
            'kode_vendor' => $vendor['number'],
            'nama_vendor' => $vendor['name'],
            'jenis_vendor' => $this->trimmedOrNull($validated['jenis_vendor'] ?? null),
            'nama_pic' => $this->trimmedOrNull($validated['nama_pic'] ?? null),
            'npwp_ktp' => $this->trimmedOrNull($validated['npwp_ktp'] ?? null) ?? $vendor['tax_number'],
            'telepon' => $this->trimmedOrNull($validated['telepon'] ?? null),
            'email' => $this->trimmedOrNull($validated['email'] ?? null),
            'media_sosial' => $this->trimmedOrNull($validated['media_sosial'] ?? null),
            'alamat' => $this->trimmedOrNull($validated['alamat'] ?? null),
            'status_vendor' => $this->trimmedOrNull($validated['status_vendor'] ?? null) ?? 'Vendor Resmi',
            'status_badan_hukum' => $this->trimmedOrNull($validated['status_badan_hukum'] ?? null) ?? 'Badan Hukum',
            'status_kualifikasi' => $this->trimmedOrNull($validated['status_kualifikasi'] ?? null) ?? 'Dalam Proses',
            'waktu_kesediaan_item' => $this->trimmedOrNull($validated['waktu_kesediaan_item'] ?? null),
            'jumlah_karyawan' => isset($validated['jumlah_karyawan']) && $validated['jumlah_karyawan'] !== ''
                ? (int) $validated['jumlah_karyawan']
                : null,
            'catatan' => $this->trimmedOrNull($validated['catatan'] ?? null),
        ];
    }

    /**
     * Jika tidak ada dokumen yang dikirim pada pembuatan awal, inisialisasi daftar dokumen standar
     * dengan `ada = false` (tidak pernah menandai tersedia tanpa bukti input).
     *
     * @param  list<array<string, mixed>>  $documents
     * @return list<array{id: ?string, kategori: string, urutan: int, nama_dokumen: string, ada: bool, keterangan: ?string}>
     */
    private function normalizeDocumentsPayload(array $documents): array
    {
        if ($documents === []) {
            $defaults = [];
            foreach (self::STANDARD_DOCUMENTS as $index => $name) {
                $defaults[] = [
                    'id' => null,
                    'kategori' => 'wajib',
                    'urutan' => $index + 1,
                    'nama_dokumen' => $name,
                    'ada' => false,
                    'keterangan' => null,
                ];
            }

            return $defaults;
        }

        $normalized = [];
        foreach (array_values($documents) as $index => $doc) {
            $normalized[] = [
                'id' => $this->trimmedOrNull($doc['id'] ?? null),
                'kategori' => (string) ($doc['kategori'] ?? 'wajib'),
                'urutan' => $index + 1,
                'nama_dokumen' => trim((string) $doc['nama_dokumen']),
                'ada' => filter_var($doc['ada'] ?? false, FILTER_VALIDATE_BOOL),
                'keterangan' => $this->trimmedOrNull($doc['keterangan'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{id: ?string, urutan: int, jenis_item: ?string, kode_item: ?string, nama_item: string, satuan: ?string, kesediaan: ?string, keterangan: ?string}>
     */
    private function normalizeItemsPayload(array $items): array
    {
        $normalized = [];
        foreach (array_values($items) as $index => $item) {
            $normalized[] = [
                'id' => $this->trimmedOrNull($item['id'] ?? null),
                'urutan' => $index + 1,
                'jenis_item' => $this->trimmedOrNull($item['jenis_item'] ?? null),
                'kode_item' => $this->trimmedOrNull($item['kode_item'] ?? null),
                'nama_item' => trim((string) $item['nama_item']),
                'satuan' => $this->trimmedOrNull($item['satuan'] ?? null),
                'kesediaan' => $this->trimmedOrNull($item['kesediaan'] ?? null),
                'keterangan' => $this->trimmedOrNull($item['keterangan'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * Sinkronisasi baris dokumen menggunakan soft delete dan pemulihan identitas (withTrashed).
     *
     * @param  list<array{id: ?string, kategori: string, urutan: int, nama_dokumen: string, ada: bool, keterangan: ?string}>  $documents
     */
    private function syncDocuments(VendorPrequalification $owner, string $tenantId, array $documents): void
    {
        VendorPrequalificationDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('prakualifikasi_id', $owner->id)
            ->delete();

        $existingById = VendorPrequalificationDocument::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('prakualifikasi_id', $owner->id)
            ->get()
            ->keyBy('id');

        $reusedIds = [];

        foreach ($documents as $doc) {
            $payload = [
                'kategori' => $doc['kategori'],
                'urutan' => $doc['urutan'],
                'nama_dokumen' => $doc['nama_dokumen'],
                'ada' => $doc['ada'],
                'keterangan' => $doc['keterangan'],
            ];

            $candidate = null;
            if ($doc['id'] !== null && $existingById->has($doc['id']) && ! isset($reusedIds[$doc['id']])) {
                $candidate = $existingById->get($doc['id']);
            } else {
                $candidate = $existingById->first(function (VendorPrequalificationDocument $row) use ($doc, $reusedIds): bool {
                    return ! isset($reusedIds[$row->id])
                        && $row->kategori === $doc['kategori']
                        && $row->nama_dokumen === $doc['nama_dokumen'];
                });
            }

            if ($candidate !== null) {
                $reusedIds[$candidate->id] = true;
                $candidate->fill($payload);
                $candidate->setAttribute('deleted_at', null);
                $candidate->save();

                continue;
            }

            VendorPrequalificationDocument::query()->create([
                'tenant_id' => $tenantId,
                'prakualifikasi_id' => $owner->id,
                ...$payload,
            ]);
        }
    }

    /**
     * Sinkronisasi baris item menggunakan soft delete dan pemulihan identitas (withTrashed).
     *
     * @param  list<array{id: ?string, urutan: int, jenis_item: ?string, kode_item: ?string, nama_item: string, satuan: ?string, kesediaan: ?string, keterangan: ?string}>  $items
     */
    private function syncItems(VendorPrequalification $owner, string $tenantId, array $items): void
    {
        VendorPrequalificationItem::query()
            ->where('tenant_id', $tenantId)
            ->where('prakualifikasi_id', $owner->id)
            ->delete();

        $existingById = VendorPrequalificationItem::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('prakualifikasi_id', $owner->id)
            ->get()
            ->keyBy('id');

        $reusedIds = [];

        foreach ($items as $item) {
            $payload = [
                'urutan' => $item['urutan'],
                'jenis_item' => $item['jenis_item'],
                'kode_item' => $item['kode_item'],
                'nama_item' => $item['nama_item'],
                'satuan' => $item['satuan'],
                'kesediaan' => $item['kesediaan'],
                'keterangan' => $item['keterangan'],
            ];

            $candidate = null;
            if ($item['id'] !== null && $existingById->has($item['id']) && ! isset($reusedIds[$item['id']])) {
                $candidate = $existingById->get($item['id']);
            } else {
                $candidate = $existingById->first(function (VendorPrequalificationItem $row) use ($item, $reusedIds): bool {
                    return ! isset($reusedIds[$row->id])
                        && $row->kode_item === $item['kode_item']
                        && $row->nama_item === $item['nama_item'];
                });
            }

            if ($candidate !== null) {
                $reusedIds[$candidate->id] = true;
                $candidate->fill($payload);
                $candidate->setAttribute('deleted_at', null);
                $candidate->save();

                continue;
            }

            VendorPrequalificationItem::query()->create([
                'tenant_id' => $tenantId,
                'prakualifikasi_id' => $owner->id,
                ...$payload,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $documents
     * @param  list<array<string, mixed>>  $items
     */
    private function computePayloadHash(array $header, array $documents, array $items, ?string $manualNumber): string
    {
        return hash('sha256', json_encode([
            'header' => $header,
            'documents' => $documents,
            'items' => $items,
            'manual_number' => $manualNumber,
        ], JSON_THROW_ON_ERROR));
    }

    private function replay(
        VendorPrequalification $existing,
        string $payloadHash,
        VendorDirectory $vendors,
        string $tenantId,
    ): JsonResponse {
        $storedHash = $existing->creation_payload_hash;

        if ($storedHash !== null && ! hash_equals($storedHash, $payloadHash)) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_conflict',
                    'message' => 'Kunci permintaan sudah dipakai untuk data prakualifikasi yang berbeda.',
                ],
            ], 409);
        }

        $vendor = $vendors->find($tenantId, $existing->vendor_id);

        return response()->json(
            ['data' => $this->presentDetail($existing, $vendor)],
            200,
            ['Idempotent-Replayed' => 'true', 'ETag' => RowVersion::etag($existing->version)],
        );
    }

    /**
     * Proyeksi ringkas untuk baris tabel daftar (tanpa menyertakan child documents/items).
     *
     * @param  array<string, array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}>  $vendorMap
     * @return array<string, mixed>
     */
    private function presentSummary(VendorPrequalification $record, array $vendorMap): array
    {
        $vendor = $vendorMap[$record->vendor_id] ?? null;

        return [
            'id' => $record->id,
            'nomor_prakualifikasi' => $record->nomor_prakualifikasi,
            'tanggal_prakualifikasi' => $record->tanggal_prakualifikasi?->format('Y-m-d') ?? (string) $record->getRawOriginal('tanggal_prakualifikasi'),
            'vendor_id' => $record->vendor_id,
            'kode_vendor' => $vendor['number'] ?? $record->kode_vendor,
            'nama_vendor' => $vendor['name'] ?? $record->nama_vendor,
            'jenis_vendor' => $record->jenis_vendor,
            'nama_pic' => $record->nama_pic,
            'telepon' => $record->telepon,
            'alamat' => $record->alamat,
            'status_vendor' => $record->status_vendor,
            'status_badan_hukum' => $record->status_badan_hukum,
            'status_kualifikasi' => $record->status_kualifikasi,
            'version' => $record->version,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    /**
     * Proyeksi lengkap untuk detail/edit card (hanya 1 bentuk field `documents`, tanpa alias duplikat).
     *
     * @param  array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string}|null  $vendor
     * @return array<string, mixed>
     */
    private function presentDetail(VendorPrequalification $record, ?array $vendor): array
    {
        return [
            'id' => $record->id,
            'nomor_prakualifikasi' => $record->nomor_prakualifikasi,
            'tanggal_prakualifikasi' => $record->tanggal_prakualifikasi?->format('Y-m-d') ?? (string) $record->getRawOriginal('tanggal_prakualifikasi'),
            'vendor_id' => $record->vendor_id,
            'kode_vendor' => $vendor['number'] ?? $record->kode_vendor,
            'nama_vendor' => $vendor['name'] ?? $record->nama_vendor,
            'jenis_vendor' => $record->jenis_vendor,
            'nama_pic' => $record->nama_pic,
            'npwp_ktp' => $record->npwp_ktp ?? ($vendor['tax_number'] ?? null),
            'telepon' => $record->telepon,
            'email' => $record->email,
            'media_sosial' => $record->media_sosial,
            'alamat' => $record->alamat,
            'status_vendor' => $record->status_vendor,
            'status_badan_hukum' => $record->status_badan_hukum,
            'status_kualifikasi' => $record->status_kualifikasi,
            'waktu_kesediaan_item' => $record->waktu_kesediaan_item,
            'jumlah_karyawan' => $record->jumlah_karyawan,
            'catatan' => $record->catatan,
            'version' => $record->version,
            'documents' => $record->documents->map(static fn (VendorPrequalificationDocument $doc): array => [
                'id' => $doc->id,
                'kategori' => $doc->kategori,
                'urutan' => $doc->urutan,
                'nama_dokumen' => $doc->nama_dokumen,
                'ada' => (bool) $doc->ada,
                'keterangan' => $doc->keterangan,
            ])->values()->all(),
            'items' => $record->items->map(static fn (VendorPrequalificationItem $item): array => [
                'id' => $item->id,
                'urutan' => $item->urutan,
                'jenis_item' => $item->jenis_item,
                'kode_item' => $item->kode_item,
                'nama_item' => $item->nama_item,
                'satuan' => $item->satuan,
                'kesediaan' => $item->kesediaan,
                'keterangan' => $item->keterangan,
            ])->values()->all(),
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
