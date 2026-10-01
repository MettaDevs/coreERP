<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MonitoringAset;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoring;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoringLine;
use Modules\Apperp\ManagementAset\Reporting\AssetSpecification;
use Modules\Apperp\ManagementAset\Services\DirektoriAset;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\AssetMonitoringStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Dokumen monitoring aset — pemeriksaan fisik (stock opname) aset tetap di satu lokasi.
 *
 * **Menyelesaikannya tidak mengubah register aset.** Dokumen ini mencatat temuan dan membekukannya
 * untuk laporan. Aset yang ternyata hilang diajukan lewat dekomisioning, aset yang ternyata berada di
 * tempat lain dipindahkan lewat mutasi; keduanya dokumen dengan wewenang dan buktinya sendiri. Padanan
 * terdekat di Business Central adalah *Physical inventory* untuk aset tetap yang tidak ada: BC hanya
 * mengenal jurnal aset tetap, dan penyesuaian register di sana juga dokumen terpisah.
 *
 * **Hasil tiap baris dihitung, tidak dipilih.** Pemeriksa mencatat ada atau tidak ada; sistem
 * membandingkannya dengan register. Aset yang ditemukan wajib masih beredar dan tercatat di lokasi yang
 * diperiksa; aset yang tidak ditemukan dinilai menurut status siklus hidupnya saja. Lihat
 * {@see AssetMonitoringStatus::result()}.
 */
class AssetMonitoringController extends Controller
{
    private const RESOURCE = 'monitoring-aset';

    /** 160 batas Core dikurangi awalan `monitoring-aset:` dan satu karakter cadangan, seperti mutasi. */
    private const MAX_CREATION_KEY = 143;

    private const HEADER = 'aset_tr_monitoring_aset';

    private const LINES = 'aset_tr_monitoring_aset_details';

    private const LOCKED = 'Monitoring yang sudah selesai tidak dapat diubah. Buat monitoring baru bila temuannya perlu dikoreksi.';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $tenant = $this->tenant($request);
        $filter = $request->validate([
            'status' => ['nullable', 'string', Rule::in(AssetMonitoringStatus::all())],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'lokasi_aset_id' => ['nullable', 'ulid'],
        ]);

        $query = AssetMonitoring::query();
        app(OrganizationScope::class)->query($query, $request, self::HEADER.'.legal_entity_id', self::HEADER.'.responsible_org_unit_id');
        if ($filter['status'] ?? null) {
            $query->where(self::HEADER.'.status', $filter['status']);
        }
        if ($filter['dari'] ?? null) {
            $query->whereDate(self::HEADER.'.tanggal', '>=', $filter['dari']);
        }
        if ($filter['sampai'] ?? null) {
            $query->whereDate(self::HEADER.'.tanggal', '<=', $filter['sampai']);
        }
        if ($filter['lokasi_aset_id'] ?? null) {
            $query->where(self::HEADER.'.lokasi_aset_id', $filter['lokasi_aset_id']);
        }

        return response()->json(['data' => $this->withLookups($query)
            ->orderByDesc(self::HEADER.'.tanggal')
            ->orderByDesc(self::HEADER.'.created_at')
            ->get()
            ->map(fn (stdClass $row): stdClass => $this->withNames($tenant, $row))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');

        return $this->document($request, $id);
    }

    public function store(Request $request, PenerbitNomorAset $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = $this->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request, false);
        $this->requireOwner($request, $data['legal_entity_id'], $data['responsible_org_unit_id'] ?? null);
        $this->validateLines($request, $data['legal_entity_id'], $data['details'], []);

        // Nomor diterbitkan setelah seluruh validasi supaya permintaan yang ditolak tidak membakar
        // counter, dan sebelum transaksi supaya kegagalan Core tidak menahan koneksi database —
        // urutan yang sama dengan mutasi dan work order.
        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        try {
            $id = DB::transaction(function () use ($tenant, $data, $key, $kode): string {
                $id = (string) Str::ulid();
                (new AssetMonitoring)->forceFill([
                    'id' => $id,
                    'tenant_id' => $tenant,
                    'creation_key' => $key,
                    'kode' => $kode,
                    'legal_entity_id' => $data['legal_entity_id'],
                    'status' => AssetMonitoringStatus::DRAFT,
                    ...$this->headerValues($data),
                ])->save();
                $this->syncLines($id, $data['lokasi_aset_id'], $data['details']);

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

    /**
     * Mengubah header dan menyamakan baris dengan daftar yang dikirim.
     *
     * Baris dicocokkan menurut aset, bukan diganti seluruhnya: baris yang asetnya masih ada
     * dipertahankan beserta nomornya, baris yang asetnya tidak dikirim lagi diarsipkan, dan aset
     * baru mendapat nomor baris berikutnya. Nomor baris tidak pernah dipakai ulang karena foto
     * bukti menempel ke nomor itu.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $monitoring = $this->find($request, $id);
        abort_unless(AssetMonitoringStatus::editable($monitoring->status), 422, self::LOCKED);

        $data = $this->validated($request, true);
        $version = RowVersion::expected($request);
        if ($data['legal_entity_id'] !== $monitoring->legal_entity_id) {
            throw ValidationException::withMessages([
                'legal_entity_id' => 'Entitas legal monitoring tidak dapat diganti; nomornya terbit untuk entitas legal ini.',
            ]);
        }
        $this->requireOwner($request, $data['legal_entity_id'], $data['responsible_org_unit_id'] ?? null);
        $this->validateLines($request, $data['legal_entity_id'], $data['details'], $this->lineAssetIds($id));

        DB::transaction(function () use ($id, $version, $data): void {
            RowVersion::claim(AssetMonitoring::query()->whereKey($id), $version);
            $updated = AssetMonitoring::query()
                ->where(['id' => $id, 'status' => AssetMonitoringStatus::DRAFT])
                ->update([...$this->headerValues($data), 'updated_at' => now()]);
            abort_unless($updated > 0, 422, self::LOCKED);
            $this->syncLines($id, $data['lokasi_aset_id'], $data['details']);
        });

        return $this->document($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $monitoring = $this->find($request, $id);
        abort_unless(AssetMonitoringStatus::editable($monitoring->status), 422, 'Monitoring yang sudah selesai tidak dapat diarsipkan; temuannya sudah menjadi dasar laporan.');
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(AssetMonitoring::query()->whereKey($id), $version);
            $updated = AssetMonitoring::query()
                ->where(['id' => $id, 'status' => AssetMonitoringStatus::DRAFT])
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            abort_unless($updated > 0, 422, 'Monitoring yang sudah selesai tidak dapat diarsipkan; temuannya sudah menjadi dasar laporan.');
        });

        return response()->json(status: 204);
    }

    /**
     * Mengisi baris dengan seluruh aset yang tercatat di lokasi dokumen.
     *
     * Termasuk aset yang sudah didekomisioning atau dilepas: menemukan aset seperti itu masih berada
     * di tempatnya adalah temuan yang dicari pemeriksaan ini. Unit organisasi dan penanggung jawab
     * pada header, bila diisi, menyempitkan pilihannya. Aset yang sudah ada di dokumen dilewati,
     * jadi tombolnya aman ditekan lagi setelah register berubah.
     */
    public function fill(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $monitoring = $this->find($request, $id);
        abort_unless(AssetMonitoringStatus::editable($monitoring->status), 422, self::LOCKED);
        $version = RowVersion::expected($request);

        $added = DB::transaction(function () use ($request, $monitoring, $id, $version): int {
            RowVersion::claim(AssetMonitoring::query()->whereKey($id), $version);
            abort_unless(
                AssetMonitoring::query()->where(['id' => $id, 'status' => AssetMonitoringStatus::DRAFT])->exists(),
                422,
                self::LOCKED,
            );

            $query = Aset::query()
                ->where('aset_tr_aset.lokasi_aset_id', $monitoring->lokasi_aset_id)
                ->where('aset_tr_aset.legal_entity_id', $monitoring->legal_entity_id)
                ->whereNotIn('aset_tr_aset.id', $this->lineAssetIds($id));
            app(OrganizationScope::class)->asetQuery($query, $request);
            if ($monitoring->responsible_org_unit_id !== null) {
                $query->where('aset_tr_aset.responsible_org_unit_id', $monitoring->responsible_org_unit_id);
            }

            $asetIds = [];
            foreach ($query->orderBy('aset_tr_aset.kode')->toBase()->pluck('aset_tr_aset.id') as $asetId) {
                $asetIds[] = (string) $asetId;
            }
            // Penanggung jawab adalah penempatan terakhir aset, bukan kolom aset, jadi disaring
            // sesudah kandidatnya dibaca. Satu lokasi memuat puluhan sampai ratusan aset.
            if ($monitoring->penanggung_jawab_user_id !== null) {
                $custodians = $this->latestCustodians($asetIds);
                $asetIds = array_values(array_filter(
                    $asetIds,
                    fn (string $asetId): bool => ($custodians[$asetId] ?? null) === $monitoring->penanggung_jawab_user_id,
                ));
            }
            $next = $this->lastLineNumber($id);
            foreach ($asetIds as $asetId) {
                AssetMonitoringLine::create([
                    'monitoring_aset_id' => $id,
                    'line_number' => ++$next,
                    'aset_id' => (string) $asetId,
                ]);
            }

            return count($asetIds);
        });

        return $this->document($request, $id, 200, [], ['ditambahkan' => $added]);
    }

    /**
     * Menyelesaikan pemeriksaan: temuan dan keadaan register pada saat ini dibekukan.
     *
     * Register aset tidak disentuh. Yang dibekukan adalah status siklus hidup, lokasi, unit, dan
     * penanggung jawab yang tercatat, beserta nilai perolehan, akumulasi penyusutan, dan nilai buku
     * dari buku komersial, supaya laporan yang dicetak kemudian tetap menyebut keadaan pada saat
     * pemeriksaan.
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'complete');
        $monitoring = $this->find($request, $id);
        abort_unless($monitoring->status === AssetMonitoringStatus::DRAFT, 422, 'Monitoring ini sudah diselesaikan.');
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($monitoring, $id, $version): void {
            // Versi diklaim lebih dulu, baru barisnya dibaca: baris yang dibaca sebelum klaim dapat
            // sudah diganti penyimpanan lain yang lolos di antaranya.
            RowVersion::claim(AssetMonitoring::query()->whereKey($id), $version);
            $updated = AssetMonitoring::query()
                ->where(['id' => $id, 'status' => AssetMonitoringStatus::DRAFT])
                ->update(['status' => AssetMonitoringStatus::COMPLETED, 'diselesaikan_pada' => now(), 'updated_at' => now()]);
            abort_unless($updated > 0, 422, 'Monitoring ini sudah diselesaikan.');

            $lines = AssetMonitoringLine::query()->where('monitoring_aset_id', $id)->orderBy('line_number')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['details' => 'Monitoring tanpa baris aset tidak dapat diselesaikan.']);
            }
            $unchecked = $lines->whereNull('ada')->pluck('line_number')->all();
            if ($unchecked !== []) {
                throw ValidationException::withMessages([
                    'details' => 'Keberadaan fisik aset pada baris '.implode(', ', $unchecked).' belum diisi.',
                ]);
            }

            $state = $this->registerState(array_values($lines->map(fn (AssetMonitoringLine $line): string => $line->aset_id)->all()));
            $names = $this->locationNames(array_values(array_unique(array_filter(array_column($state, 'lokasi_aset_id')))));
            foreach ($lines as $line) {
                $current = $state[$line->aset_id] ?? null;
                $registered = $current['lokasi_aset_id'] ?? null;
                // Lokasi tercatat bisa berubah sejak baris disimpan; keterangan otomatis ditulis dari
                // lokasi yang dibekukan, kecuali pemeriksa sudah mengisi keterangannya sendiri.
                $note = ($line->keterangan ?? '') === ''
                    ? AssetMonitoringStatus::locationNote($line->ada, $registered, $monitoring->lokasi_aset_id, $registered === null ? null : ($names[$registered] ?? null))
                    : null;
                $line->forceFill([
                    ...($note === null ? [] : ['keterangan' => $note]),
                    'sistem_lifecycle_state' => $current['lifecycle_state'] ?? null,
                    'sistem_lokasi_id' => $current['lokasi_aset_id'] ?? null,
                    'sistem_org_unit_id' => $current['org_unit_id'] ?? null,
                    'sistem_custodian_user_id' => $current['custodian_user_id'] ?? null,
                    'nilai_perolehan' => $current['nilai_perolehan'] ?? null,
                    'akumulasi_penyusutan' => $current['akumulasi_penyusutan'] ?? null,
                    'nilai_buku' => $current['nilai_buku'] ?? null,
                    'hasil' => AssetMonitoringStatus::result($current['lifecycle_state'] ?? null, $line->ada, $registered, $monitoring->lokasi_aset_id),
                ])->save();
            }
        });

        return $this->document($request, $id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $replacing): array
    {
        $tenant = $this->tenant($request);

        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['nullable', 'ulid'],
            'penanggung_jawab_user_id' => ['nullable', 'string', 'max:64'],
            'lokasi_aset_id' => ['required', 'ulid', Rule::exists('aset_m_lokasi_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            // Pada PATCH daftar baris wajib dikirim, walau kosong: yang tidak dikirim diarsipkan,
            // dan "tidak dikirim" tidak boleh terbaca sama dengan "tidak diubah".
            'details' => [$replacing ? 'present' : 'sometimes', 'array'],
            'details.*.aset_id' => ['required', 'ulid'],
            'details.*.ada' => ['nullable', 'boolean'],
            'details.*.kondisi_aset_id' => ['nullable', 'ulid'],
            'details.*.keterangan' => ['nullable', 'string', 'max:2000'],
        ]);

        // Dijadikan list di batas: objek JSON berkunci teks lolos aturan `array`.
        $data['details'] = array_values($data['details'] ?? []);

        return $data;
    }

    /**
     * Pemilik dokumen menurut kebijakan organisasi.
     *
     * Dokumen tanpa unit organisasi tidak punya unit untuk dicocokkan dengan hibah pengguna, jadi
     * hanya pengguna yang menjangkau seluruh organisasi yang boleh membuatnya — dan hanya mereka yang
     * melihatnya di daftar.
     */
    private function requireOwner(Request $request, string $legalEntityId, ?string $unitId): void
    {
        $scope = app(OrganizationScope::class);
        if ($unitId === null) {
            if (! $scope->unrestricted($request)) {
                throw ValidationException::withMessages([
                    'responsible_org_unit_id' => 'Pilih unit organisasi. Monitoring tanpa unit hanya dapat dibuat oleh pengguna yang menjangkau seluruh unit kerja.',
                ]);
            }

            return;
        }

        $scope->require($request, $legalEntityId, $unitId);
    }

    /**
     * Aset baru pada dokumen wajib berada dalam jangkauan organisasi pengguna dan milik entitas legal
     * dokumen. Aset yang sudah ada di dokumen tidak diperiksa ulang: ia sah saat ditambahkan, dan aset
     * yang kemudian dimutasi keluar dari jangkauan pengguna tidak boleh membuat temuannya tidak dapat
     * disimpan.
     *
     * Aset yang sudah didekomisioning atau dilepas boleh masuk. Menemukannya masih ada adalah temuan.
     *
     * @param  list<array<string, mixed>>  $details
     * @param  list<string>  $current
     */
    private function validateLines(Request $request, string $legalEntityId, array $details, array $current): void
    {
        $asetIds = array_map(static fn (array $detail): string => (string) $detail['aset_id'], $details);
        if (count(array_unique($asetIds)) !== count($asetIds)) {
            throw ValidationException::withMessages(['details' => 'Satu aset hanya boleh muncul sekali pada satu monitoring.']);
        }

        $new = array_values(array_diff(array_unique($asetIds), $current));
        if ($new !== []) {
            $query = Aset::query()->whereIn('aset_tr_aset.id', $new);
            app(OrganizationScope::class)->asetQuery($query, $request);
            $found = $query->toBase()->get(['aset_tr_aset.id', 'aset_tr_aset.kode', 'aset_tr_aset.legal_entity_id']);
            if ($found->count() !== count($new)) {
                throw ValidationException::withMessages(['details' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
            }
            foreach ($found as $aset) {
                if ($aset->legal_entity_id !== $legalEntityId) {
                    throw ValidationException::withMessages([
                        'details' => 'Aset '.$aset->kode.' berada di entitas legal lain; monitoring hanya boleh memuat aset milik entitas legal dokumen ini.',
                    ]);
                }
            }
        }

        $kondisiIds = array_values(array_unique(array_filter(array_column($details, 'kondisi_aset_id'))));
        if ($kondisiIds !== [] && KondisiAset::query()->whereIn('id', $kondisiIds)->where('aktif', true)->count() !== count($kondisiIds)) {
            throw ValidationException::withMessages(['details' => 'Kondisi aset tidak ditemukan atau sudah tidak aktif.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerValues(array $data): array
    {
        return [
            'responsible_org_unit_id' => $data['responsible_org_unit_id'] ?? null,
            'penanggung_jawab_user_id' => $data['penanggung_jawab_user_id'] ?? null,
            'lokasi_aset_id' => $data['lokasi_aset_id'],
            'tanggal' => $data['tanggal'],
            'keterangan' => $data['keterangan'] ?? null,
        ];
    }

    /**
     * Menyamakan baris dengan daftar yang dikirim.
     *
     * Aset yang ditemukan ada padahal tercatat di lokasi lain diberi keterangan "Tercatat di …" bila
     * pemeriksa belum menulis keterangan sendiri, supaya temuan itu terbaca tanpa membuka asetnya.
     *
     * @param  list<array<string, mixed>>  $details
     */
    private function syncLines(string $monitoringId, string $checkedLocationId, array $details): void
    {
        $existing = AssetMonitoringLine::query()->where('monitoring_aset_id', $monitoringId)->get()->keyBy('aset_id');
        $next = $this->lastLineNumber($monitoringId);
        $sent = [];
        $registered = [];
        foreach (Aset::withTrashed()->whereIn('id', array_column($details, 'aset_id'))->toBase()->get(['id', 'lokasi_aset_id']) as $aset) {
            $registered[(string) $aset->id] = $aset->lokasi_aset_id === null ? null : (string) $aset->lokasi_aset_id;
        }
        $names = $this->locationNames(array_values(array_unique(array_filter($registered))));

        foreach ($details as $detail) {
            $asetId = (string) $detail['aset_id'];
            $sent[] = $asetId;
            $present = isset($detail['ada']) ? (bool) $detail['ada'] : null;
            $note = $detail['keterangan'] ?? null;
            if ($note === null || $note === '') {
                $location = $registered[$asetId] ?? null;
                $note = AssetMonitoringStatus::locationNote($present, $location, $checkedLocationId, $location === null ? null : ($names[$location] ?? null));
            }
            $values = [
                'ada' => $present,
                'kondisi_aset_id' => $detail['kondisi_aset_id'] ?? null,
                'keterangan' => $note,
            ];

            $line = $existing->get($asetId);
            if ($line instanceof AssetMonitoringLine) {
                $line->fill($values);
                if ($line->isDirty()) {
                    $line->save();
                }

                continue;
            }

            AssetMonitoringLine::create(['monitoring_aset_id' => $monitoringId, 'line_number' => ++$next, 'aset_id' => $asetId, ...$values]);
        }

        // `toBase()`: `except()` milik koleksi Eloquent menyaring menurut primary key model, bukan
        // menurut kunci koleksi, dan akan mengarsipkan seluruh baris.
        $removed = $existing->toBase()->except($sent)->pluck('id')->all();
        if ($removed !== []) {
            // Diarsipkan, tidak dihapus: nomor barisnya tetap tercatat untuk lampiran yang menempel.
            AssetMonitoringLine::query()->whereIn('id', $removed)->delete();
        }
    }

    private function lastLineNumber(string $monitoringId): int
    {
        return (int) AssetMonitoringLine::withTrashed()->where('monitoring_aset_id', $monitoringId)->max('line_number');
    }

    /**
     * Keadaan register untuk sejumlah aset, dibaca sekaligus.
     *
     * Penanggung jawab dibaca dari penempatan terakhir, karena aset tidak menyimpannya. Nilai dibaca
     * dari buku komersial — buku tanpa master atau buku ber-lapisan `current` — dan bila ada lebih
     * dari satu, yang kodenya paling awal, supaya satu aset selalu menghasilkan satu angka.
     *
     * @param  list<string>  $asetIds
     * @return array<string, array{lifecycle_state: ?string, lokasi_aset_id: ?string, org_unit_id: ?string, custodian_user_id: ?string, nilai_perolehan: ?string, akumulasi_penyusutan: ?string, nilai_buku: ?string}>
     */
    private function registerState(array $asetIds): array
    {
        if ($asetIds === []) {
            return [];
        }

        $custodians = $this->latestCustodians($asetIds);
        $books = [];
        $commercial = BukuAset::query()
            ->whereIn('aset_id', $asetIds)
            ->where(fn ($query) => $query->whereNull('buku_id')->orWhereIn('buku_id', BukuPenyusutan::query()->where('posting_layer', 'current')->select('id')))
            ->orderBy('book_code')
            ->toBase()
            ->get(['aset_id', 'acquisition_value', 'accumulated_depreciation', 'net_book_value']);
        foreach ($commercial as $book) {
            $books[(string) $book->aset_id] ??= $book;
        }

        $state = [];
        foreach (Aset::withTrashed()->whereIn('id', $asetIds)->toBase()->get(['id', 'lifecycle_state', 'lokasi_aset_id', 'responsible_org_unit_id', 'acquisition_value']) as $aset) {
            $id = (string) $aset->id;
            $book = $books[$id] ?? null;
            $state[$id] = [
                'lifecycle_state' => $aset->lifecycle_state === null ? null : (string) $aset->lifecycle_state,
                'lokasi_aset_id' => $aset->lokasi_aset_id === null ? null : (string) $aset->lokasi_aset_id,
                'org_unit_id' => $aset->responsible_org_unit_id === null ? null : (string) $aset->responsible_org_unit_id,
                'custodian_user_id' => $custodians[$id] ?? null,
                'nilai_perolehan' => (string) ($book->acquisition_value ?? $aset->acquisition_value),
                'akumulasi_penyusutan' => $book === null ? null : (string) $book->accumulated_depreciation,
                'nilai_buku' => $book === null ? null : (string) $book->net_book_value,
            ];
        }

        return $state;
    }

    /**
     * Penanggung jawab pada penempatan terakhir tiap aset; aset tanpa penempatan tidak punya kunci.
     *
     * @param  list<string>  $asetIds
     * @return array<string, ?string>
     */
    private function latestCustodians(array $asetIds): array
    {
        if ($asetIds === []) {
            return [];
        }

        $custodians = [];
        $placements = PenempatanAset::query()
            ->whereIn('aset_id', $asetIds)
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->toBase()
            ->get(['aset_id', 'custodian_user_id']);
        foreach ($placements as $placement) {
            $asetId = (string) $placement->aset_id;
            if (! array_key_exists($asetId, $custodians)) {
                $custodians[$asetId] = $placement->custodian_user_id === null ? null : (string) $placement->custodian_user_id;
            }
        }

        return $custodians;
    }

    /** @return list<string> */
    private function lineAssetIds(string $monitoringId): array
    {
        return array_values(AssetMonitoringLine::query()
            ->where('monitoring_aset_id', $monitoringId)
            ->get(['aset_id'])
            ->map(fn (AssetMonitoringLine $line): string => $line->aset_id)
            ->all());
    }

    /**
     * Jawaban satu dokumen beserta barisnya, versi, dan ETag.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $meta
     */
    private function document(Request $request, string $id, int $status = 200, array $headers = [], array $meta = []): JsonResponse
    {
        $tenant = $this->tenant($request);
        $query = AssetMonitoring::query()->where(self::HEADER.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::HEADER.'.legal_entity_id', self::HEADER.'.responsible_org_unit_id');
        $row = $this->withNames($tenant, $this->withLookups($query)->firstOrFail());
        $row->details = $this->lines($id, $tenant, (string) $row->lokasi_aset_id, $row->status === AssetMonitoringStatus::COMPLETED);

        return response()->json(
            array_filter(['data' => $row, 'meta' => $meta === [] ? null : $meta], static fn ($value): bool => $value !== null),
            $status,
            [...$headers, 'ETag' => RowVersion::etag((int) $row->version)],
        );
    }

    /** Dokumen dalam jangkauan organisasi pengguna; 404 bila tidak ada, diarsipkan, atau di luar jangkauan. */
    private function find(Request $request, string $id): AssetMonitoring
    {
        $query = AssetMonitoring::query()->where(self::HEADER.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::HEADER.'.legal_entity_id', self::HEADER.'.responsible_org_unit_id');

        return $query->firstOrFail();
    }

    private function replay(string $key): ?stdClass
    {
        return AssetMonitoring::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    /**
     * Nama lokasi, jumlah baris, jumlah yang belum diperiksa, dan jumlah yang tidak sesuai ikut
     * dibaca, supaya daftar tidak perlu satu permintaan per dokumen. Selama draf, hasil tidak sesuai
     * dihitung dari status aset sekarang; sesudah selesai, dari hasil yang dibekukan.
     *
     * @param  Builder<AssetMonitoring>  $query
     */
    private function withLookups(Builder $query): QueryBuilder
    {
        $mismatch = AssetMonitoringLine::query()
            ->selectRaw('count(*)')
            ->join('aset_tr_aset as aset_hitung', function (JoinClause $join): void {
                $join->on('aset_hitung.id', '=', self::LINES.'.aset_id')->on('aset_hitung.tenant_id', '=', self::LINES.'.tenant_id');
            })
            ->whereColumn(self::LINES.'.monitoring_aset_id', self::HEADER.'.id')
            ->where(function ($query): void {
                $query->where(self::LINES.'.hasil', AssetMonitoringStatus::MISMATCH)
                    ->orWhere(function ($query): void {
                        // Selama draf: ditemukan padahal sudah tidak beredar atau tercatat di lokasi
                        // lain, atau tidak ditemukan padahal masih beredar — aturan yang sama dengan
                        // AssetMonitoringStatus::result().
                        [$decommissioned, $disposed] = StatusAset::tidakLagiBeredar();
                        $query->whereNull(self::LINES.'.hasil')
                            ->whereNotNull(self::LINES.'.ada')
                            ->whereRaw(
                                'case when "'.self::LINES.'"."ada" '
                                .'then ("aset_hitung"."lifecycle_state" in (?, ?) or "aset_hitung"."lokasi_aset_id" is distinct from "'.self::HEADER.'"."lokasi_aset_id") '
                                .'else "aset_hitung"."lifecycle_state" not in (?, ?) end',
                                [$decommissioned, $disposed, $decommissioned, $disposed],
                            );
                    });
            });

        return $query
            ->leftJoin('aset_m_lokasi_aset as lokasi', function (JoinClause $join): void {
                $join->on('lokasi.id', '=', self::HEADER.'.lokasi_aset_id')->on('lokasi.tenant_id', '=', self::HEADER.'.tenant_id');
            })
            ->selectSub(AssetMonitoringLine::query()->selectRaw('count(*)')->whereColumn(self::LINES.'.monitoring_aset_id', self::HEADER.'.id')->toBase(), 'jumlah_baris')
            ->selectSub(AssetMonitoringLine::query()->selectRaw('count(*)')->whereColumn(self::LINES.'.monitoring_aset_id', self::HEADER.'.id')->whereNull(self::LINES.'.ada')->toBase(), 'jumlah_belum_diperiksa')
            ->selectSub($mismatch->toBase(), 'jumlah_tidak_sesuai')
            ->addSelect([self::HEADER.'.*', 'lokasi.kode as lokasi_aset_kode', 'lokasi.nama as lokasi_aset_nama'])
            ->toBase();
    }

    /**
     * Nama unit kerja dan nama orang di sebelah idnya. Nama dibaca saat dilayani, tidak dibekukan:
     * nama berubah karena sebab di luar aset.
     */
    private function withNames(string $tenantId, stdClass $row): stdClass
    {
        $directory = app(DirektoriAset::class);
        $row->responsible_org_unit_nama = $directory->namaUnit($tenantId, $row->responsible_org_unit_id ?? null);
        $row->penanggung_jawab_nama = $directory->namaOrang($tenantId, $row->penanggung_jawab_user_id ?? null);

        return $row;
    }

    /**
     * Baris beserta keadaan register dan hasilnya.
     *
     * Dokumen selesai membaca nilai yang dibekukan. Dokumen draf membaca keadaan aset sekarang dan
     * menghitung hasilnya langsung, sehingga layar selalu menunjukkan apa yang akan dibekukan bila
     * pemeriksaan diselesaikan saat ini. Klien tidak mengirim satu pun nilai `sistem_*` kembali.
     *
     * @return Collection<int, stdClass>
     */
    private function lines(string $monitoringId, string $tenantId, string $checkedLocationId, bool $frozen): Collection
    {
        $directory = app(DirektoriAset::class);
        $rows = AssetMonitoringLine::query()
            ->leftJoin('aset_tr_aset as aset', function (JoinClause $join): void {
                $join->on('aset.id', '=', self::LINES.'.aset_id')->on('aset.tenant_id', '=', self::LINES.'.tenant_id');
            })
            ->leftJoin('aset_m_model_aset as model', function (JoinClause $join): void {
                $join->on('model.id', '=', 'aset.model_aset_id')->on('model.tenant_id', '=', 'aset.tenant_id');
            })
            ->leftJoin('aset_m_kondisi_aset as kondisi', function (JoinClause $join): void {
                $join->on('kondisi.id', '=', self::LINES.'.kondisi_aset_id')->on('kondisi.tenant_id', '=', self::LINES.'.tenant_id');
            })
            ->where(self::LINES.'.monitoring_aset_id', $monitoringId)
            ->orderBy(self::LINES.'.line_number')
            ->toBase()
            ->get([
                self::LINES.'.*',
                'aset.kode as aset_kode',
                'aset.nama as aset_nama',
                'aset.model_number as aset_model_number',
                'aset.serial_number as aset_serial_number',
                'model.nama as aset_model_nama',
                'kondisi.nama as kondisi_aset_nama',
            ]);

        $state = $frozen ? [] : $this->registerState(array_values($rows->map(fn (stdClass $row): string => (string) $row->aset_id)->all()));
        $locationIds = [];
        foreach ($rows as $row) {
            $locationIds[] = $frozen ? $row->sistem_lokasi_id : ($state[(string) $row->aset_id]['lokasi_aset_id'] ?? null);
        }
        $locations = $this->locationNames(array_values(array_unique(array_filter($locationIds))));

        return $rows->map(function (stdClass $row) use ($frozen, $state, $locations, $directory, $tenantId, $checkedLocationId): stdClass {
            $current = $state[(string) $row->aset_id] ?? [];
            $present = $row->ada === null ? null : (bool) $row->ada;
            $row->ada = $present;
            $row->spesifikasi = $this->specification($row);
            if (! $frozen) {
                $row->sistem_lifecycle_state = $current['lifecycle_state'] ?? null;
                $row->sistem_lokasi_id = $current['lokasi_aset_id'] ?? null;
                $row->sistem_org_unit_id = $current['org_unit_id'] ?? null;
                $row->sistem_custodian_user_id = $current['custodian_user_id'] ?? null;
                $row->nilai_perolehan = $current['nilai_perolehan'] ?? null;
                $row->akumulasi_penyusutan = $current['akumulasi_penyusutan'] ?? null;
                $row->nilai_buku = $current['nilai_buku'] ?? null;
                $row->hasil = AssetMonitoringStatus::result($row->sistem_lifecycle_state, $present, $row->sistem_lokasi_id, $checkedLocationId);
            }
            $row->sistem_lifecycle_label = StatusAset::label($row->sistem_lifecycle_state);
            $row->sistem_lokasi_nama = $locations[(string) $row->sistem_lokasi_id] ?? null;
            $row->sistem_org_unit_nama = $directory->namaUnit($tenantId, $row->sistem_org_unit_id);
            $row->sistem_custodian_nama = $directory->namaOrang($tenantId, $row->sistem_custodian_user_id);

            return $row;
        });
    }

    private function specification(stdClass $row): string
    {
        return AssetSpecification::describe(
            $row->aset_model_nama === null ? null : (string) $row->aset_model_nama,
            $row->aset_model_number === null ? null : (string) $row->aset_model_number,
            $row->aset_serial_number === null ? null : (string) $row->aset_serial_number,
        );
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function locationNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (LokasiAset::withTrashed()->whereIn('id', $ids)->toBase()->get(['id', 'nama']) as $location) {
            $names[(string) $location->id] = (string) $location->nama;
        }

        return $names;
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
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
