<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Reclassification;

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
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassification;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationBook;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationLine;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\ReclassificationPosting;
use Modules\Apperp\ManagementAset\Services\ReclassificationPostingFailed;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PostingCheckLines;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Dokumen reklasifikasi aset — pindah group aset, atau pecah sebagian nilai aset ke aset baru.
 *
 * Alurnya jurnal aset tetap Business Central: draf disusun dan boleh diubah atau diarsipkan, *Preview Posting*
 * menampilkan jurnal dan pemindahannya, lalu *Post* memindah nilainya, melahirkan aset baru (pecah) atau
 * mengganti group aset (pindah group), dan menerbitkan jurnalnya di satu transaksi; dokumennya terkunci.
 * Aturan pemindahan dan jurnalnya ada di `ReclassificationPosting`.
 */
class AssetReclassificationController extends Controller
{
    private const RESOURCE = 'reklasifikasi-aset';

    /** 160 batas Core dikurangi awalan `reklasifikasi-aset:` dan satu karakter cadangan. */
    private const MAX_CREATION_KEY = 140;

    private const HEADER = 'aset_tr_reklasifikasi_aset';

    private const LOCKED = 'Reklasifikasi yang sudah diposting tidak dapat diubah. Buat reklasifikasi baru bila perlu dikoreksi.';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'status' => ['nullable', 'string', Rule::in([AssetReclassification::DRAFT, AssetReclassification::POSTED])],
            'jenis' => ['nullable', 'string', Rule::in(array_keys(AssetReclassification::KINDS))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);
        $query = AssetReclassification::query();
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
            ->selectSub(AssetReclassificationLine::query()->selectRaw('count(*)')->whereColumn('aset_tr_reklasifikasi_aset_details.reklasifikasi_aset_id', self::HEADER.'.id')->toBase(), 'jumlah_baris')
            ->addSelect([self::HEADER.'.*'])
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
        $this->validateLines($request, $data, []);

        // Nomor diterbitkan setelah seluruh validasi dan sebelum transaksi, urutan yang sama dengan
        // penyesuaian nilai dan mutasi.
        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return $this->numberFailed($exception);
        }

        try {
            $id = DB::transaction(function () use ($tenant, $data, $key, $kode): string {
                $id = (string) Str::ulid();
                (new AssetReclassification)->forceFill([
                    'id' => $id,
                    'tenant_id' => $tenant,
                    'creation_key' => $key,
                    'kode' => $kode,
                    'legal_entity_id' => $data['legal_entity_id'],
                    'status' => AssetReclassification::DRAFT,
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

    /** Mengubah header draf dan menyamakan baris dengan daftar yang dikirim, dicocokkan menurut id baris. */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $dokumen = $this->find($request, $id);
        abort_unless($dokumen->status === AssetReclassification::DRAFT, 422, self::LOCKED);
        $data = $this->validated($request, true);
        $version = RowVersion::expected($request);
        if ($data['legal_entity_id'] !== $dokumen->legal_entity_id) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Entitas legal reklasifikasi tidak dapat diganti; nomornya terbit untuk entitas legal ini.']);
        }
        if ($data['jenis'] !== $dokumen->jenis) {
            throw ValidationException::withMessages(['jenis' => 'Jenis reklasifikasi tidak dapat diganti sesudah draf disimpan. Arsipkan draf ini dan buat yang baru.']);
        }
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $this->validateLines($request, $data, $this->lineAssetIds($id));

        DB::transaction(function () use ($id, $version, $data): void {
            RowVersion::claim(AssetReclassification::query()->whereKey($id), $version);
            $updated = AssetReclassification::query()
                ->where(['id' => $id, 'status' => AssetReclassification::DRAFT])
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
        abort_unless($dokumen->status === AssetReclassification::DRAFT, 422, 'Reklasifikasi yang sudah diposting tidak dapat diarsipkan; nilainya sudah berpindah.');
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(AssetReclassification::query()->whereKey($id), $version);
            $updated = AssetReclassification::query()
                ->where(['id' => $id, 'status' => AssetReclassification::DRAFT])
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            abort_unless($updated > 0, 422, 'Reklasifikasi yang sudah diposting tidak dapat diarsipkan; nilainya sudah berpindah.');
        });

        return response()->json(status: 204);
    }

    /**
     * Pratinjau posting (K-22, *Preview Posting* BC): pemindahan per baris, jurnal dengan pemeriksaan yang sama
     * persis dengan penerbitan, dan yang menahan posting. Tidak menyimpan apa pun. Baris yang masih tertahan
     * tidak ikut disusun.
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $this->guardAny($request, ['post', 'update']);
        $dokumen = $this->find($request, $id);
        abort_unless($dokumen->status === AssetReclassification::DRAFT, 422, self::LOCKED);
        $layanan = app(ReclassificationPosting::class);
        $rows = $layanan->rows($dokumen);
        $masalah = $layanan->blockers($dokumen, $rows);
        $siap = isset($masalah['currency_code']) || isset($masalah['details']) ? [] : array_values(array_filter(
            $rows,
            static fn (stdClass $row, int $indeks): bool => array_filter(array_keys($masalah), static fn (string $kunci): bool => str_starts_with($kunci, 'details.'.$indeks.'.')) === [],
            ARRAY_FILTER_USE_BOTH,
        ));
        $plan = $siap === [] ? [] : $layanan->plan($dokumen, $siap);
        try {
            $hasil = $plan === [] ? ['note' => null, 'posting' => null] : $layanan->preview($dokumen, $plan);
        } catch (ReclassificationPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }
        $posting = $hasil['posting'];
        $payload = $posting['payload'] ?? null;

        return response()->json(['data' => [
            'blockers' => array_map(static fn (string $field, string $message): array => ['field' => $field, 'message' => $message], array_keys($masalah), array_values($masalah)),
            'note' => $hasil['note'],
            'moves' => array_map(fn (array $rencana): array => $this->moveSummary($rencana), $plan),
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
     * Memposting draf: nilai berpindah, aset baru lahir atau group berganti, jurnal terbit, dan dokumennya
     * terkunci — satu transaksi. Nomor aset baru pecahan diterbitkan sebelum transaksi, dengan kunci per baris,
     * sehingga percobaan ulang memakai nomor yang sama.
     */
    public function post(Request $request, string $id, AssetNumberSequenceIssuer $numbers): JsonResponse
    {
        $this->guard($request, 'post');
        $dokumen = $this->find($request, $id);
        abort_unless($dokumen->status === AssetReclassification::DRAFT, 422, self::LOCKED);
        $version = RowVersion::expected($request);
        $layanan = app(ReclassificationPosting::class);
        $rows = $layanan->rows($dokumen);
        $masalah = $layanan->blockers($dokumen, $rows);
        if ($masalah !== []) {
            throw ValidationException::withMessages($masalah);
        }

        $codes = [];
        if ($dokumen->jenis === AssetReclassification::SPLIT) {
            try {
                foreach ($rows as $row) {
                    $codes[(string) $row->id] = $numbers->issue('management-aset.aset', $this->tenant($request), 'aset:reklasifikasi:'.$row->id, $dokumen->legal_entity_id);
                }
            } catch (NumberSequenceException $exception) {
                return $this->numberFailed($exception);
            }
        }

        try {
            DB::transaction(function () use ($id, $version, $codes, $layanan): void {
                // Versi diklaim lebih dulu, baru barisnya dibaca dan buku asetnya dikunci: dua reklasifikasi
                // serentak atas aset yang sama tidak boleh memindah saldo yang sama.
                RowVersion::claim(AssetReclassification::query()->whereKey($id), $version);
                $dokumen = AssetReclassification::query()->whereKey($id)->firstOrFail();
                abort_unless($dokumen->status === AssetReclassification::DRAFT, 422, self::LOCKED);
                $rows = $layanan->rows($dokumen, true);
                $masalah = $layanan->blockers($dokumen, $rows);
                if ($masalah !== []) {
                    throw ValidationException::withMessages($masalah);
                }
                if (array_diff(array_map(static fn (stdClass $row): string => (string) $row->id, $dokumen->jenis === AssetReclassification::SPLIT ? $rows : []), array_keys($codes)) !== []) {
                    throw ValidationException::withMessages(['details' => 'Baris reklasifikasi berubah saat diposting. Muat ulang lalu posting lagi.']);
                }

                $hasil = $layanan->post($dokumen, $layanan->plan($dokumen, $rows), $codes);
                AssetReclassification::query()->whereKey($id)->update([
                    'status' => AssetReclassification::POSTED,
                    'diposting_pada' => now(),
                    'posting_id' => $hasil['posting']['posting_id'] ?? null,
                    'updated_at' => now(),
                ]);
            });
        } catch (ReclassificationPostingFailed $kegagalan) {
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
            'jenis' => ['required', 'string', Rule::in(array_keys(AssetReclassification::KINDS))],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            // Ikut ke keterangan jurnal di aplikasi finance, jadi wajib dan dibatasi.
            'keterangan' => ['required', 'string', 'max:250'],
            // Pada PATCH daftar baris wajib dikirim, walau kosong: yang tidak dikirim diarsipkan.
            'details' => [$replacing ? 'present' : 'sometimes', 'array'],
            'details.*.id' => ['nullable', 'ulid'],
            'details.*.aset_id' => ['required', 'ulid'],
            'details.*.group_aset_tujuan_id' => ['nullable', 'ulid', Rule::exists('aset_m_group_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'details.*.persen' => ['nullable', 'numeric', 'gt:0', 'lt:100', 'decimal:0,6'],
            'details.*.nilai_perolehan' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'details.*.nama_aset_baru' => ['nullable', 'string', 'max:255'],
            'details.*.keterangan' => ['nullable', 'string', 'max:500'],
        ], [
            'details.*.persen.lt' => 'Persentase harus di bawah 100. Untuk memindah seluruh aset, pakai pindah group.',
        ]);
        $data['details'] = array_values($data['details'] ?? []);

        $masalah = [];
        foreach ($data['details'] as $indeks => $baris) {
            $persen = ($baris['persen'] ?? null) !== null;
            $nilai = ($baris['nilai_perolehan'] ?? null) !== null;
            if ($data['jenis'] === AssetReclassification::TRANSFER) {
                if (($baris['group_aset_tujuan_id'] ?? null) === null) {
                    $masalah['details.'.$indeks.'.group_aset_tujuan_id'] = 'Pilih group tujuan.';
                }
                if ($persen || $nilai) {
                    $masalah['details.'.$indeks.'.persen'] = 'Pindah group memindah seluruh nilai aset; persentase dan nilai tidak diisi.';
                }
            } elseif ($persen === $nilai) {
                $masalah['details.'.$indeks.'.persen'] = 'Isi salah satu: persentase atau nilai perolehan yang dipecah.';
            }
        }
        if ($masalah !== []) {
            throw ValidationException::withMessages($masalah);
        }

        return $data;
    }

    /**
     * Aset baru pada dokumen wajib berada dalam jangkauan organisasi pengguna, milik entitas legal dokumen, dan
     * belum dilepas. Pindah group memuat satu aset sekali saja; pecah boleh memecah satu aset ke beberapa aset
     * baru. Aset yang sudah ada di dokumen tidak diperiksa ulang di sini; posting memeriksa seluruhnya.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $current
     */
    private function validateLines(Request $request, array $data, array $current): void
    {
        $asetIds = array_map(static fn (array $detail): string => (string) $detail['aset_id'], $data['details']);
        if ($data['jenis'] === AssetReclassification::TRANSFER && count(array_unique($asetIds)) !== count($asetIds)) {
            throw ValidationException::withMessages(['details' => 'Satu aset hanya boleh muncul sekali pada satu reklasifikasi pindah group.']);
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
            if ($aset->legal_entity_id !== $data['legal_entity_id']) {
                throw ValidationException::withMessages(['details' => 'Aset '.$aset->kode.' berada di entitas legal lain; reklasifikasi hanya boleh memuat aset milik entitas legal dokumen ini.']);
            }
            if (StatusAset::sudahDilepas($aset->lifecycle_state)) {
                throw ValidationException::withMessages(['details' => 'Aset '.$aset->kode.' sudah dilepas, jadi tidak dapat direklasifikasi.']);
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
            'tanggal' => $data['tanggal'],
            'keterangan' => trim((string) $data['keterangan']),
        ];
    }

    /**
     * Menyamakan baris dengan daftar yang dikirim. Baris yang dikirim dengan `id`-nya dipertahankan beserta
     * nomornya; yang tidak dikirim lagi diarsipkan; baris tanpa `id` mendapat nomor berikutnya. Nomor baris tidak
     * dipakai ulang karena lampiran menempel ke nomor itu. Dicocokkan menurut id, bukan aset, karena pecah boleh
     * memuat aset yang sama di beberapa baris.
     *
     * @param  list<array<string, mixed>>  $details
     */
    private function syncLines(string $reclassificationId, array $details): void
    {
        $existing = AssetReclassificationLine::query()->where('reklasifikasi_aset_id', $reclassificationId)->get()->keyBy('id');
        $next = (int) AssetReclassificationLine::withTrashed()->where('reklasifikasi_aset_id', $reclassificationId)->max('line_number');
        $kept = [];
        foreach ($details as $detail) {
            $values = [
                'aset_id' => (string) $detail['aset_id'],
                'group_aset_tujuan_id' => $detail['group_aset_tujuan_id'] ?? null,
                'persen' => isset($detail['persen']) ? (string) $detail['persen'] : null,
                'nilai_perolehan' => isset($detail['nilai_perolehan']) ? (string) $detail['nilai_perolehan'] : null,
                'nama_aset_baru' => isset($detail['nama_aset_baru']) && trim((string) $detail['nama_aset_baru']) !== '' ? trim((string) $detail['nama_aset_baru']) : null,
                'keterangan' => $detail['keterangan'] ?? null,
            ];
            $line = isset($detail['id']) ? $existing->get((string) $detail['id']) : null;
            if ($line instanceof AssetReclassificationLine) {
                $kept[] = (string) $line->id;
                $line->fill($values);
                if ($line->isDirty()) {
                    $line->save();
                }

                continue;
            }
            AssetReclassificationLine::create(['reklasifikasi_aset_id' => $reclassificationId, 'line_number' => ++$next, ...$values]);
        }
        // `toBase()`: `except()` milik koleksi Eloquent menyaring menurut primary key model.
        $removed = $existing->toBase()->except($kept)->keys()->all();
        if ($removed !== []) {
            AssetReclassificationLine::query()->whereIn('id', $removed)->delete();
        }
    }

    /** @return list<string> */
    private function lineAssetIds(string $reclassificationId): array
    {
        return array_values(AssetReclassificationLine::query()->where('reklasifikasi_aset_id', $reclassificationId)->pluck('aset_id')->map(static fn ($id): string => (string) $id)->unique()->all());
    }

    /**
     * Pemindahan satu baris untuk pratinjau: aset, group asal dan tujuan, dan yang dipindah di setiap buku.
     *
     * @param  array{row: stdClass, from_group: string, to_group: string, journal: bool, asset_acquisition: BigDecimal, books: array<string, array<string, mixed>>}  $rencana
     * @return array<string, mixed>
     */
    private function moveSummary(array $rencana): array
    {
        return [
            'line_number' => (int) $rencana['row']->line_number,
            'aset_kode' => $rencana['row']->aset->kode,
            'from_group_id' => $rencana['from_group'],
            'to_group_id' => $rencana['to_group'],
            'journal' => $rencana['journal'],
            'nilai_perolehan_aset' => (string) $rencana['asset_acquisition'],
            'books' => array_values(array_map(static fn (array $bagian): array => [
                'book_code' => (string) $bagian['book']->book_code,
                'nilai_perolehan' => (string) $bagian['acquisition'],
                'akumulasi_penyusutan' => (string) $bagian['accumulated'],
                'penurunan_nilai' => (string) $bagian['write_down'],
                'kenaikan_nilai' => (string) $bagian['appreciation'],
                'nilai_buku' => (string) $bagian['acquisition']->minus($bagian['accumulated'])->minus($bagian['write_down'])->plus($bagian['appreciation']),
            ], $rencana['books'])),
        ];
    }

    /**
     * Jawaban satu dokumen beserta baris, nama aset dan group, keadaan jurnalnya, versi, dan ETag. Sesudah
     * diposting, baris membawa aset baru (pecah) dan yang dipindah per buku.
     *
     * @param  array<string, string>  $headers
     */
    private function document(Request $request, string $id, int $status = 200, array $headers = []): JsonResponse
    {
        $dokumen = $this->find($request, $id);
        $tenant = $this->tenant($request);
        $lines = AssetReclassificationLine::query()->where('reklasifikasi_aset_id', $id)->orderBy('line_number')->toBase()->get();
        $asetIds = $lines->pluck('aset_id')->merge($lines->pluck('aset_baru_id'))->filter()->unique()->values()->all();
        $aset = Aset::withTrashed()->whereIn('id', $asetIds)->toBase()->get(['id', 'kode', 'nama', 'group_aset_id', 'acquisition_value'])->keyBy('id');
        $groupIds = $lines->pluck('group_aset_tujuan_id')->merge($lines->pluck('group_aset_asal_id'))->merge($aset->pluck('group_aset_id'))->filter()->unique()->values()->all();
        $grup = GroupAset::withTrashed()->whereIn('id', $groupIds)->toBase()->get(['id', 'kode', 'nama'])->keyBy('id');
        $pindah = AssetReclassificationBook::query()->where('reklasifikasi_aset_id', $id)->orderBy('buku_id')->toBase()->get()->groupBy('reklasifikasi_aset_detail_id');
        $keadaan = $dokumen->posting_id === null ? null : app(PostingFeed::class)->status($tenant, $dokumen->posting_id);
        $namaGroup = static fn (?string $groupId): ?string => $groupId === null ? null : ($grup[$groupId]->nama ?? null);

        $details = $lines->map(static function (stdClass $line) use ($aset, $namaGroup, $pindah): array {
            $asal = $aset[$line->aset_id] ?? null;
            $baru = $line->aset_baru_id === null ? null : ($aset[$line->aset_baru_id] ?? null);
            $groupAsal = $line->group_aset_asal_id ?? $asal->group_aset_id ?? null;

            return [
                'id' => $line->id,
                'line_number' => (int) $line->line_number,
                'aset_id' => $line->aset_id,
                'aset_kode' => $asal->kode ?? null,
                'aset_nama' => $asal->nama ?? null,
                'nilai_perolehan_aset' => $asal === null ? null : (string) $asal->acquisition_value,
                'group_aset_asal_id' => $groupAsal,
                'group_aset_asal_nama' => $namaGroup($groupAsal),
                'group_aset_tujuan_id' => $line->group_aset_tujuan_id,
                'group_aset_tujuan_nama' => $namaGroup($line->group_aset_tujuan_id),
                'persen' => $line->persen === null ? null : (string) $line->persen,
                'nilai_perolehan' => $line->nilai_perolehan === null ? null : (string) $line->nilai_perolehan,
                'nama_aset_baru' => $line->nama_aset_baru,
                'keterangan' => $line->keterangan,
                'aset_baru_id' => $line->aset_baru_id,
                'aset_baru_kode' => $baru->kode ?? null,
                'nilai_perolehan_dipindah' => $line->nilai_perolehan_dipindah === null ? null : (string) $line->nilai_perolehan_dipindah,
                'books' => ($pindah[$line->id] ?? collect())->map(static fn (stdClass $buku): array => [
                    'buku_id' => $buku->buku_id,
                    'nilai_perolehan' => (string) $buku->nilai_perolehan,
                    'akumulasi_penyusutan' => (string) $buku->akumulasi_penyusutan,
                    'penurunan_nilai' => (string) $buku->penurunan_nilai,
                    'kenaikan_nilai' => (string) $buku->kenaikan_nilai,
                    'dijurnal' => (bool) $buku->dijurnal,
                ])->values()->all(),
            ];
        })->all();

        return response()->json(['data' => [
            ...(array) $this->withNames($tenant, (object) $dokumen->only([
                'id', 'kode', 'legal_entity_id', 'responsible_org_unit_id', 'jenis', 'keterangan', 'status', 'posting_id', 'version',
            ])),
            'tanggal' => $dokumen->tanggal->toDateString(),
            'diposting_pada' => $dokumen->diposting_pada?->toIso8601String(),
            'posting' => $keadaan === null ? null : ['posting_id' => $keadaan['posting_id'], 'status' => $keadaan['status']],
            'details' => $details,
        ]], $status, [...$headers, 'ETag' => RowVersion::etag((int) $dokumen->version)]);
    }

    /** Dokumen dalam jangkauan organisasi pengguna; 404 bila tidak ada, diarsipkan, atau di luar jangkauan. */
    private function find(Request $request, string $id): AssetReclassification
    {
        $query = AssetReclassification::query()->where(self::HEADER.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::HEADER.'.legal_entity_id', self::HEADER.'.responsible_org_unit_id');

        return $query->firstOrFail();
    }

    private function replay(string $key): ?stdClass
    {
        return AssetReclassification::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    private function withNames(string $tenantId, stdClass $row): stdClass
    {
        $row->responsible_org_unit_nama = app(AssetOrganizationDirectory::class)->unitName($tenantId, $row->responsible_org_unit_id ?? null);

        return $row;
    }

    private function postingFailed(ReclassificationPostingFailed $failure): JsonResponse
    {
        return response()->json(['error' => ['code' => 'posting_failed', 'message' => $failure->getMessage()]], 500);
    }

    private function numberFailed(NumberSequenceException $exception): JsonResponse
    {
        return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
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
