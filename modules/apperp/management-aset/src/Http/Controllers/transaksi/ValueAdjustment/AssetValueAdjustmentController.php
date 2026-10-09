<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\ValueAdjustment;

use App\Platform\Modules\Contracts\PostingFeed;
use App\Platform\Modules\Contracts\RowVersion;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustment;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustmentLine;
use Modules\Apperp\ManagementAset\Services\AssetCancellationEngine;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\ValueAdjustmentPosting;
use Modules\Apperp\ManagementAset\Services\ValueAdjustmentPostingFailed;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PostingCheckLines;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Dokumen penyesuaian nilai aset — penurunan nilai (write-down, impairment PSAK 48 / IAS 36) atau kenaikan
 * nilai (appreciation, revaluasi PSAK 16 / IAS 16) atas satu buku penyusutan.
 *
 * Alurnya jurnal aset tetap Business Central: draf disusun dan boleh diubah atau diarsipkan, *Preview
 * Posting* menampilkan jurnalnya, lalu *Post* mengubah nilai buku aset dan menerbitkan jurnalnya di satu
 * transaksi, dan dokumennya terkunci. Jurnal hanya terbit untuk aset yang buku dokumennya adalah buku yang
 * di-post ke finance; lihat `ValueAdjustmentPosting`.
 *
 * **Pengaruhnya ke penyusutan.** Penurunan dan kenaikan nilai adalah bagian nilai buku (`Part of Book
 * Value` bawaan BC) dan dasar penyusutan berikutnya: garis lurus membagi nilai buku baru ke sisa masa
 * manfaat (`DepreciationCalculator`), saldo menurun sudah selalu menghitung dari nilai buku. Pelepasan
 * membalik keduanya.
 */
class AssetValueAdjustmentController extends Controller
{
    private const RESOURCE = 'penyesuaian-nilai-aset';

    /** 160 batas Core dikurangi awalan `penyesuaian-nilai-aset:` dan satu karakter cadangan. */
    private const MAX_CREATION_KEY = 135;

    private const HEADER = 'aset_tr_penyesuaian_nilai_aset';

    private const LOCKED = 'Penyesuaian yang sudah diposting tidak dapat diubah. Buat penyesuaian baru bila nilainya perlu dikoreksi.';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'status' => ['nullable', 'string', Rule::in([AssetValueAdjustment::DRAFT, AssetValueAdjustment::POSTED])],
            'jenis' => ['nullable', 'string', Rule::in(array_keys(AssetValueAdjustment::KINDS))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);
        $query = AssetValueAdjustment::query();
        app(OrganizationScope::class)->query($query, $request, self::HEADER.'.legal_entity_id', self::HEADER.'.responsible_org_unit_id');
        foreach (['status', 'jenis'] as $kolom) {
            if ($filter[$kolom] ?? null) {
                $query->where(self::HEADER.'.'.$kolom, $filter[$kolom]);
            }
        }
        if ($filter['dari'] ?? null) {
            $query->whereDate(self::HEADER.'.tanggal', '>=', $filter['dari']);
        }
        if ($filter['sampai'] ?? null) {
            $query->whereDate(self::HEADER.'.tanggal', '<=', $filter['sampai']);
        }

        $tenant = $this->tenant($request);
        $rows = $query
            ->leftJoin('aset_m_buku_penyusutan as buku', function ($join): void {
                $join->on('buku.id', '=', self::HEADER.'.buku_id')->on('buku.tenant_id', '=', self::HEADER.'.tenant_id');
            })
            ->selectSub(AssetValueAdjustmentLine::query()->selectRaw('count(*)')->whereColumn('aset_tr_penyesuaian_nilai_aset_details.penyesuaian_nilai_aset_id', self::HEADER.'.id')->toBase(), 'jumlah_baris')
            ->selectSub(AssetValueAdjustmentLine::query()->selectRaw('coalesce(sum(nilai), 0)')->whereColumn('aset_tr_penyesuaian_nilai_aset_details.penyesuaian_nilai_aset_id', self::HEADER.'.id')->toBase(), 'total_nilai')
            ->addSelect([self::HEADER.'.*', 'buku.kode as buku_kode', 'buku.nama as buku_nama'])
            ->orderByDesc(self::HEADER.'.tanggal')
            ->orderByDesc(self::HEADER.'.created_at')
            ->toBase()
            ->get()
            ->map(fn (stdClass $row): stdClass => $this->withNames($tenant, $row));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');

        return $this->document($request, $id);
    }

    public function store(Request $request, AssetNumberSequenceIssuer $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = $this->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request, false);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $this->validateLines($request, $data['legal_entity_id'], $data['details'], []);

        // Nomor diterbitkan setelah seluruh validasi supaya permintaan yang ditolak tidak membakar counter,
        // dan sebelum transaksi supaya kegagalan Core tidak menahan koneksi database — urutan yang sama
        // dengan monitoring dan mutasi.
        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        try {
            $id = DB::transaction(function () use ($tenant, $data, $key, $kode): string {
                $id = (string) Str::ulid();
                (new AssetValueAdjustment)->forceFill([
                    'id' => $id,
                    'tenant_id' => $tenant,
                    'creation_key' => $key,
                    'kode' => $kode,
                    'legal_entity_id' => $data['legal_entity_id'],
                    'status' => AssetValueAdjustment::DRAFT,
                    ...$this->headerValues($data),
                ])->save();
                $this->syncLines($id, $data['details']);

                return $id;
            });
        } catch (QueryException $exception) {
            $existing = $this->replay($key);
            if (! $existing) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        return $this->document($request, $id, 201, ['Location' => $request->url().'/'.$id]);
    }

    /** Mengubah header draf dan menyamakan baris dengan daftar yang dikirim, dicocokkan menurut aset. */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $dokumen = $this->find($request, $id);
        abort_unless($dokumen->status === AssetValueAdjustment::DRAFT, 422, self::LOCKED);
        $data = $this->validated($request, true);
        $version = RowVersion::expected($request);
        if ($data['legal_entity_id'] !== $dokumen->legal_entity_id) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Entitas legal penyesuaian tidak dapat diganti; nomornya terbit untuk entitas legal ini.']);
        }
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $this->validateLines($request, $data['legal_entity_id'], $data['details'], $this->lineAssetIds($id));

        DB::transaction(function () use ($id, $version, $data): void {
            RowVersion::claim(AssetValueAdjustment::query()->whereKey($id), $version);
            $updated = AssetValueAdjustment::query()
                ->where(['id' => $id, 'status' => AssetValueAdjustment::DRAFT])
                ->update([...$this->headerValues($data), 'updated_at' => now()]);
            abort_unless($updated > 0, 422, self::LOCKED);
            $this->syncLines($id, $data['details']);
        });

        return $this->document($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $dokumen = $this->find($request, $id);
        abort_unless($dokumen->status === AssetValueAdjustment::DRAFT, 422, 'Penyesuaian yang sudah diposting tidak dapat diarsipkan; nilai bukunya sudah berubah.');
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(AssetValueAdjustment::query()->whereKey($id), $version);
            $updated = AssetValueAdjustment::query()
                ->where(['id' => $id, 'status' => AssetValueAdjustment::DRAFT])
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            abort_unless($updated > 0, 422, 'Penyesuaian yang sudah diposting tidak dapat diarsipkan; nilai bukunya sudah berubah.');
        });

        return response()->json(status: 204);
    }

    /**
     * Pratinjau jurnal penyesuaian (K-22, *Preview Posting* BC): jurnal dengan pemeriksaan yang sama persis
     * dengan penerbitan, dan yang menahan posting. Tidak menyimpan apa pun.
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $this->guardAny($request, ['post', 'update']);
        $dokumen = $this->find($request, $id);
        abort_unless($dokumen->status === AssetValueAdjustment::DRAFT, 422, self::LOCKED);
        $layanan = app(ValueAdjustmentPosting::class);
        $rows = $layanan->rows($dokumen);
        $masalah = $layanan->blockers($dokumen, $rows);
        try {
            // Tanpa baris, atau dengan nilai yang tidak dapat dijurnal sama persis, jurnalnya tidak disusun.
            $hasil = $rows === [] || isset($masalah['currency_code']) ? ['note' => null, 'posting' => null] : $layanan->preview($dokumen, $rows);
        } catch (ValueAdjustmentPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }
        $posting = $hasil['posting'];
        $payload = $posting['payload'] ?? null;

        return response()->json(['data' => [
            'blockers' => array_map(static fn (string $field, string $message): array => ['field' => $field, 'message' => $message], array_keys($masalah), array_values($masalah)),
            'note' => $hasil['note'],
            'posting' => $posting === null ? null : [
                'posting_id' => $posting['posting_id'],
                'status' => $posting['status'],
                'posting_date' => $payload['posting_date'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'total' => $payload['totals']['debit'] ?? null,
                'lines' => PostingCheckLines::from($payload),
                'problems' => $posting['problems'],
            ],
        ]]);
    }

    /**
     * Memposting draf: nilai buku setiap aset berubah, nilai sebelum dan sesudahnya dibekukan di baris,
     * jurnalnya terbit, dan dokumennya terkunci — satu transaksi.
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'post');
        $this->find($request, $id);
        $version = RowVersion::expected($request);

        try {
            DB::transaction(function () use ($id, $version): void {
                // Versi diklaim lebih dulu, baru barisnya dibaca.
                RowVersion::claim(AssetValueAdjustment::query()->whereKey($id), $version);
                $dokumen = AssetValueAdjustment::query()->whereKey($id)->firstOrFail();
                abort_unless($dokumen->status === AssetValueAdjustment::DRAFT, 422, self::LOCKED);

                $layanan = app(ValueAdjustmentPosting::class);
                // Buku aset dikunci: dua penyesuaian serentak atas aset yang sama tidak boleh membaca nilai
                // buku yang sama.
                $rows = $layanan->rows($dokumen, true);
                $masalah = $layanan->blockers($dokumen, $rows);
                if ($masalah !== []) {
                    throw ValidationException::withMessages($masalah);
                }
                // Jurnalnya disusun dari nilai buku sebelum diubah.
                $hasil = $layanan->publish($dokumen, $rows);

                $turun = $dokumen->jenis === AssetValueAdjustment::WRITE_DOWN;
                foreach ($rows as $row) {
                    $nilai = BigDecimal::of((string) $row->nilai);
                    $sebelum = BigDecimal::of((string) $row->book->net_book_value);
                    $saldo = $turun ? 'write_down_amount' : 'appreciation_amount';
                    // Nilai baru dihitung dari baris buku yang sudah dikunci `rows(…, true)`, jadi tidak ada
                    // perubahan lain yang dapat menyelip di antaranya.
                    BukuAset::query()->whereKey($row->book->id)->update([
                        $saldo => (string) BigDecimal::of((string) $row->book->{$saldo})->plus($nilai),
                        'net_book_value' => (string) ($turun ? $sebelum->minus($nilai) : $sebelum->plus($nilai)),
                        'updated_at' => now(),
                    ]);
                    AssetValueAdjustmentLine::query()->whereKey($row->id)->update([
                        'nilai_buku_sebelum' => (string) $sebelum,
                        'nilai_buku_sesudah' => (string) ($turun ? $sebelum->minus($nilai) : $sebelum->plus($nilai)),
                        'updated_at' => now(),
                    ]);
                }
                AssetValueAdjustment::query()->whereKey($id)->update([
                    'status' => AssetValueAdjustment::POSTED,
                    'diposting_pada' => now(),
                    'posting_id' => $hasil['posting']['posting_id'] ?? null,
                    'updated_at' => now(),
                ]);
            });
        } catch (ValueAdjustmentPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }

        return $this->document($request, $id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $replacing): array
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['required', 'ulid'],
            'jenis' => ['required', 'string', Rule::in(array_keys(AssetValueAdjustment::KINDS))],
            'buku_id' => ['required', 'ulid', Rule::exists('aset_m_buku_penyusutan', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            // Ikut ke keterangan jurnal di aplikasi finance, jadi wajib dan dibatasi.
            'keterangan' => ['required', 'string', 'max:250'],
            // Pada PATCH daftar baris wajib dikirim, walau kosong: yang tidak dikirim diarsipkan.
            'details' => [$replacing ? 'present' : 'sometimes', 'array'],
            'details.*.aset_id' => ['required', 'ulid'],
            'details.*.nilai' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'details.*.keterangan' => ['nullable', 'string', 'max:500'],
        ], [
            'details.*.nilai.gt' => 'Nilai penyesuaian harus lebih dari nol; arahnya ditentukan jenis penyesuaian.',
        ]);
        $data['details'] = array_values($data['details'] ?? []);

        return $data;
    }

    /**
     * Aset baru pada dokumen wajib berada dalam jangkauan organisasi pengguna, milik entitas legal dokumen,
     * dan belum dilepas. Aset yang sudah ada di dokumen tidak diperiksa ulang di sini; posting memeriksa
     * seluruhnya.
     *
     * @param  list<array<string, mixed>>  $details
     * @param  list<string>  $current
     */
    private function validateLines(Request $request, string $legalEntityId, array $details, array $current): void
    {
        $asetIds = array_map(static fn (array $detail): string => (string) $detail['aset_id'], $details);
        if (count(array_unique($asetIds)) !== count($asetIds)) {
            throw ValidationException::withMessages(['details' => 'Satu aset hanya boleh muncul sekali pada satu penyesuaian.']);
        }
        $new = array_values(array_diff(array_unique($asetIds), $current));
        if ($new === []) {
            return;
        }
        $query = Aset::query()->whereIn('aset_tr_aset.id', $new);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $found = $query->toBase()->get(['aset_tr_aset.id', 'aset_tr_aset.kode', 'aset_tr_aset.legal_entity_id', 'aset_tr_aset.lifecycle_state']);
        if ($found->count() !== count($new)) {
            throw ValidationException::withMessages(['details' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }
        foreach ($found as $aset) {
            if ($aset->legal_entity_id !== $legalEntityId) {
                throw ValidationException::withMessages(['details' => 'Aset '.$aset->kode.' berada di entitas legal lain; penyesuaian hanya boleh memuat aset milik entitas legal dokumen ini.']);
            }
            if (StatusAset::sudahDilepas($aset->lifecycle_state)) {
                throw ValidationException::withMessages(['details' => 'Aset '.$aset->kode.' sudah dilepas, jadi nilainya tidak dapat disesuaikan lagi.']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerValues(array $data): array
    {
        return [
            'responsible_org_unit_id' => $data['responsible_org_unit_id'],
            'jenis' => $data['jenis'],
            'buku_id' => $data['buku_id'],
            'tanggal' => $data['tanggal'],
            'keterangan' => trim((string) $data['keterangan']),
        ];
    }

    /**
     * Menyamakan baris dengan daftar yang dikirim. Baris yang asetnya masih dikirim dipertahankan beserta
     * nomornya; yang tidak dikirim lagi diarsipkan; aset baru mendapat nomor berikutnya. Nomor baris tidak
     * dipakai ulang karena lampiran menempel ke nomor itu.
     *
     * @param  list<array<string, mixed>>  $details
     */
    private function syncLines(string $adjustmentId, array $details): void
    {
        $existing = AssetValueAdjustmentLine::query()->where('penyesuaian_nilai_aset_id', $adjustmentId)->get()->keyBy('aset_id');
        $next = (int) AssetValueAdjustmentLine::withTrashed()->where('penyesuaian_nilai_aset_id', $adjustmentId)->max('line_number');
        $sent = [];
        foreach ($details as $detail) {
            $asetId = (string) $detail['aset_id'];
            $sent[] = $asetId;
            $values = ['nilai' => (string) $detail['nilai'], 'keterangan' => $detail['keterangan'] ?? null];
            $line = $existing->get($asetId);
            if ($line instanceof AssetValueAdjustmentLine) {
                $line->fill($values);
                if ($line->isDirty()) {
                    $line->save();
                }

                continue;
            }
            AssetValueAdjustmentLine::create(['penyesuaian_nilai_aset_id' => $adjustmentId, 'line_number' => ++$next, 'aset_id' => $asetId, ...$values]);
        }
        // `toBase()`: `except()` milik koleksi Eloquent menyaring menurut primary key model.
        $removed = $existing->toBase()->except($sent)->pluck('id')->all();
        if ($removed !== []) {
            AssetValueAdjustmentLine::query()->whereIn('id', $removed)->delete();
        }
    }

    /** @return list<string> */
    private function lineAssetIds(string $adjustmentId): array
    {
        return array_values(AssetValueAdjustmentLine::query()->where('penyesuaian_nilai_aset_id', $adjustmentId)->pluck('aset_id')->map(static fn ($id): string => (string) $id)->all());
    }

    /**
     * Jawaban satu dokumen beserta baris, nilai buku tiap aset, keadaan jurnalnya, versi, dan ETag.
     *
     * @param  array<string, string>  $headers
     */
    private function document(Request $request, string $id, int $status = 200, array $headers = []): JsonResponse
    {
        $dokumen = $this->find($request, $id);
        $tenant = $this->tenant($request);
        $buku = BukuPenyusutan::withTrashed()->whereKey($dokumen->buku_id)->toBase()->first(['kode', 'nama', 'posting_layer']);
        $rows = app(ValueAdjustmentPosting::class)->rows($dokumen);
        $diposting = in_array($dokumen->status, [AssetValueAdjustment::POSTED, 'cancelled'], true);
        $turun = $dokumen->jenis === AssetValueAdjustment::WRITE_DOWN;
        $keadaan = $dokumen->posting_id === null ? null : app(PostingFeed::class)->status($tenant, $dokumen->posting_id);
        $frozen = $diposting ? AssetValueAdjustmentLine::query()->where('penyesuaian_nilai_aset_id', $id)->toBase()->get(['id', 'nilai_buku_sebelum', 'nilai_buku_sesudah'])->keyBy('id') : collect();

        $details = array_map(function (stdClass $row) use ($diposting, $turun, $frozen): array {
            $nilai = BigDecimal::of((string) $row->nilai);
            // Selama draf, nilai buku dibaca dari buku aset sekarang, supaya layar menunjukkan apa yang akan
            // berubah bila diposting saat ini. Sesudah diposting, dari nilai yang dibekukan.
            $sebelum = $diposting ? $frozen[$row->id]->nilai_buku_sebelum : ($row->book->net_book_value ?? null);
            $sesudah = $diposting ? $frozen[$row->id]->nilai_buku_sesudah : ($sebelum === null ? null : (string) ($turun ? BigDecimal::of((string) $sebelum)->minus($nilai) : BigDecimal::of((string) $sebelum)->plus($nilai)));

            return [
                'id' => $row->id,
                'line_number' => (int) $row->line_number,
                'aset_id' => $row->aset_id,
                'aset_kode' => $row->aset->kode ?? null,
                'aset_nama' => $row->aset->nama ?? null,
                'nilai' => (string) $row->nilai,
                'keterangan' => $row->keterangan,
                'nilai_buku_sebelum' => $sebelum === null ? null : (string) $sebelum,
                'nilai_buku_sesudah' => $sesudah === null ? null : (string) $sesudah,
            ];
        }, $rows);

        return response()->json(['data' => [
            ...(array) $this->withNames($tenant, (object) $dokumen->only([
                'id', 'kode', 'legal_entity_id', 'responsible_org_unit_id', 'jenis', 'buku_id', 'keterangan', 'status', 'posting_id', 'version',
            ])),
            'tanggal' => $dokumen->tanggal->toDateString(),
            'diposting_pada' => $dokumen->diposting_pada?->toIso8601String(),
            'buku_kode' => $buku->kode ?? null,
            'buku_nama' => $buku->nama ?? null,
            'buku_di_post' => ($buku->posting_layer ?? BukuPenyusutan::POSTING_LAYER_NONE) !== BukuPenyusutan::POSTING_LAYER_NONE,
            'posting' => $keadaan === null ? null : ['posting_id' => $keadaan['posting_id'], 'status' => $keadaan['status']],
            'details' => $details,
            'cancellation' => app(AssetCancellationEngine::class)->summary('penyesuaian-nilai-aset', $id),
        ]], $status, [...$headers, 'ETag' => RowVersion::etag((int) $dokumen->version)]);
    }

    /** Dokumen dalam jangkauan organisasi pengguna; 404 bila tidak ada, diarsipkan, atau di luar jangkauan. */
    private function find(Request $request, string $id): AssetValueAdjustment
    {
        $query = AssetValueAdjustment::query()->where(self::HEADER.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::HEADER.'.legal_entity_id', self::HEADER.'.responsible_org_unit_id');

        return $query->firstOrFail();
    }

    private function replay(string $key): ?stdClass
    {
        return AssetValueAdjustment::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    private function withNames(string $tenantId, stdClass $row): stdClass
    {
        $row->responsible_org_unit_nama = app(AssetOrganizationDirectory::class)->unitName($tenantId, $row->responsible_org_unit_id ?? null);

        return $row;
    }

    private function postingFailed(ValueAdjustmentPostingFailed $failure): JsonResponse
    {
        return response()->json(['error' => ['code' => 'posting_failed', 'message' => $failure->getMessage()]], 500);
    }

    private function creationKey(Request $request): string
    {
        return (string) validator(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:'.self::MAX_CREATION_KEY, 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate()['key'];
    }

    private function guard(Request $request, string $action): void
    {
        $this->guardAny($request, [$action]);
    }

    /** @param  list<string>  $actions */
    private function guardAny(Request $request, array $actions): void
    {
        $granted = $request->attributes->get('coreerp.permissions', []);
        foreach ($actions as $action) {
            if (in_array('management-aset.'.self::RESOURCE.'.'.$action, $granted, true)) {
                return;
            }
        }
        abort(403);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
