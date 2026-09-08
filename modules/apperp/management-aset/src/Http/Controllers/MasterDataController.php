<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Services\NumberSequenceClient;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use Modules\Apperp\ManagementAset\Support\MasterParent;

/**
 * Perilaku bersama seluruh master Management Aset: hak akses per resource, batas
 * tenant, idempotency, kode dari Number Sequence Core, induk rantai klasifikasi,
 * dan arsip yang tidak memutus referensi aktif.
 */
abstract class MasterDataController extends Controller
{
    /**
     * ID app pada kode permission dan reference nomor. Nilai statis yang harus sama
     * dengan `app.yaml`; sengaja bukan konfigurasi runtime agar env tidak dapat
     * menggeser hak akses.
     */
    protected const APP_ID = 'management-aset';

    /** Slug resource pada route, kode permission, dan reference nomor. */
    abstract protected function resource(): string;

    /** @return class-string<MasterData> */
    abstract protected function model(): string;

    /**
     * Induk master ini; kosong bila berdiri sendiri. Beberapa master memiliki lebih dari
     * satu induk yang saling lepas (misalnya model aset yang menunjuk pabrikan dan jenis),
     * jadi daftar ini tidak menyiratkan urutan atau penyaringan bertingkat.
     *
     * @return list<MasterParent>
     */
    protected function parentMasters(): array
    {
        return [];
    }

    /** @return list<MasterChild> */
    protected function childMasters(): array
    {
        return [];
    }

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $parents = $this->parentMasters();
        $parentFilterRules = [];
        foreach ($parents as $parent) {
            $parentFilterRules[$parent->column] = ['nullable', 'string', 'size:26'];
        }
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'aktif' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ...$parentFilterRules,
        ]);

        $query = $this->prepareQuery($this->tenantQuery($request), $request);
        // Perbandingan eksplisit terhadap string kosong, bukan truthiness: pencarian "0"
        // adalah kata kunci yang sah dan tidak boleh diperlakukan sebagai tanpa filter.
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn (Builder $builder) => $builder
                ->whereRaw('LOWER(kode) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(nama) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }
        // `?aktif=` kosong berarti tanpa filter. Tanpa pemeriksaan null, ia akan
        // berubah menjadi `aktif = false` dan hanya menampilkan data tidak aktif.
        if (($validated['aktif'] ?? null) !== null) {
            $query->where('aktif', filter_var($validated['aktif'], FILTER_VALIDATE_BOOL));
        }
        // Filter induk bersifat aditif: master dengan dua induk dapat disaring pada
        // keduanya sekaligus karena tidak ada hubungan bertingkat di antara mereka.
        foreach ($parents as $parent) {
            if ($parentId = $validated[$parent->column] ?? null) {
                $query->where($parent->column, $parentId);
            }
        }

        $page = $query->orderBy('kode')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map($this->present(...))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request, NumberSequenceClient $numbers): JsonResponse
    {
        $this->requirePermission($request, 'create');
        $creationKey = (string) $request->header('Idempotency-Key');
        // Core membatasi idempotency_key pada 160 karakter dan kunci yang dikirim ke sana
        // diawali slug resource. Slug terpanjang `item-checklist-maintenance` (26) plus ':'
        // menyisakan 133, jadi batas app harus 133 agar tidak pernah ditolak Core sebagai 503.
        validator(['key' => $creationKey], ['key' => ['required', 'string', 'max:133', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $tenantId = $this->tenantId($request);
        $data = $request->validate($this->writeRules($tenantId, creating: true));
        $payload = $this->payload($data);

        if ($existing = $this->creationKeyQuery($tenantId, $creationKey)->first()) {
            return $this->replay($existing, $payload);
        }
        $this->afterWriteValidation($data, $tenantId, creating: true);

        try {
            $kode = $numbers->issue(
                static::APP_ID.'.'.$this->resource(),
                $tenantId,
                $this->resource().':'.$creationKey,
            );
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->status);
        }

        try {
            $record = DB::transaction(fn () => $this->newQuery()->create([
                'tenant_id' => $tenantId,
                'creation_key' => $creationKey,
                'kode' => $kode,
                ...$payload,
            ]));
        } catch (QueryException $exception) {
            $existing = $this->creationKeyQuery($tenantId, $creationKey)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $payload);
        }

        return response()->json(['data' => $this->present($record)], 201, [
            'Location' => url('/api/v1/'.$this->resource().'/'.$record->getKey()),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'read');

        return response()->json(['data' => $this->present($this->find($request, $id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'update');
        $tenantId = $this->tenantId($request);
        $data = $request->validate($this->writeRules($tenantId, creating: false));
        $write = function () use ($request, $id, $data, $tenantId): JsonResponse {
            $record = $this->find($request, $id, $this->updateUnderLock());
            $this->afterWriteValidation($data, $tenantId, creating: false, record: $record);
            $this->rejectParentCycle($record, $data);
            $record->update($this->changes($data));
            // Induk boleh berpindah, jadi relasi lama dibuang agar dimuat ulang saat disajikan.
            foreach ($this->parentMasters() as $parent) {
                $record->unsetRelation($parent->relation);
            }

            return response()->json(['data' => $this->present($record)]);
        };

        return $this->updateUnderLock() ? DB::transaction($write) : $write();
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'archive');
        $record = $this->find($request, $id);
        if ($child = $this->unarchivedChild($record)) {
            return response()->json(['error' => [
                'code' => 'referenced_by_children',
                'message' => 'Data ini masih dipakai '.$child->label.' yang belum diarsipkan. Arsipkan data turunannya lebih dahulu.',
            ]], 409);
        }
        $record->delete();

        return response()->json(status: 204);
    }

    /** @return Builder<MasterData> */
    private function newQuery(): Builder
    {
        $model = $this->model();

        return $model::query();
    }

    /** @return Builder<MasterData> */
    private function tenantQuery(Request $request): Builder
    {
        $query = $this->newQuery()->where('tenant_id', $this->tenantId($request));
        foreach ($this->parentMasters() as $parent) {
            $query->with($parent->eagerLoad());
        }

        return $query;
    }

    /**
     * Unique (tenant_id, creation_key) juga mencakup record yang sudah diarsipkan, jadi
     * pencarian replay wajib menembus soft delete. Tanpa `withTrashed()`, retry dengan
     * kunci milik record yang sudah diarsipkan tidak menemukan apa pun, menerbitkan nomor
     * kedua, lalu menabrak unique index dan berakhir sebagai 500.
     *
     * @return Builder<MasterData>
     */
    private function creationKeyQuery(string $tenantId, string $creationKey): Builder
    {
        return $this->newQuery()->withTrashed()->where('tenant_id', $tenantId)->where('creation_key', $creationKey);
    }

    private function find(Request $request, string $id, bool $lock = false): MasterData
    {
        $query = $this->prepareQuery($this->tenantQuery($request), $request);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    /** Master yang menunjuk dirinya sendiri (contohnya lokasi) tidak boleh membentuk siklus. */
    private function rejectParentCycle(MasterData $record, array $data): void
    {
        foreach ($this->parentMasters() as $parent) {
            // Hanya induk yang menunjuk tabel master ini sendiri yang dapat membentuk siklus.
            // Wajib `continue` dan bukan `return`: induk lain pada master yang sama masih
            // harus diperiksa, dan urutan induk tidak dijamin.
            if (! array_key_exists($parent->column, $data) || $parent->table !== $record->getTable()) {
                continue;
            }
            $parentId = $data[$parent->column];
            abort_if($parentId === $record->getKey(), 422, 'Data tidak dapat menjadi induk dirinya sendiri.');
            while ($parentId) {
                abort_if($parentId === $record->getKey(), 422, 'Lokasi induk tidak boleh membentuk siklus.');
                $parentId = DB::table($parent->table)->where('tenant_id', $record->tenant_id)->where('id', $parentId)->value($parent->column);
            }
        }
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    private function requirePermission(Request $request, string $action): void
    {
        $permission = static::APP_ID.'.'.$this->resource().'.'.$action;
        if (in_array($permission, $request->attributes->get('coreerp.permissions', []), true)) {
            return;
        }

        // Kode permission memang disebutkan: ia sudah publik pada manifest app dan
        // menolong admin tenant menemukan duty yang belum ditugaskan.
        abort(response()->json(['error' => [
            'code' => 'forbidden',
            'message' => 'Hak '.$permission.' belum dimiliki pengguna pada tenant aktif.',
        ]], 403));
    }

    /** @return array<string, list<mixed>> */
    private function writeRules(string $tenantId, bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];
        $rules = [
            'nama' => [...$required, 'string', 'max:150'],
            'keterangan' => [...$optional, 'string', 'max:2000'],
            'aktif' => ['sometimes', 'boolean'],
        ];

        foreach ($this->parentMasters() as $parent) {
            $parentRequired = $parent->required ? $required : $optional;
            $rules[$parent->column] = [...$parentRequired, 'string', 'size:26', $this->parentExists($parent, $tenantId)];
        }

        return [...$rules, ...$this->extraRules($tenantId, $creating)];
    }

    /** Induk wajib berada pada tenant yang sama dan belum diarsipkan. */
    private function parentExists(MasterParent $parent, string $tenantId): Exists
    {
        return Rule::exists($parent->table, 'id')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $parentColumns = [];
        foreach ($this->parentMasters() as $parent) {
            $parentColumns[$parent->column] = $data[$parent->column] ?? null;
        }

        return [
            ...$parentColumns,
            'nama' => trim($data['nama']),
            'keterangan' => array_key_exists('keterangan', $data) ? $this->trimmedOrNull($data['keterangan']) : null,
            // Dinormalkan ke boolean asli supaya replay() membandingkan nilai yang setipe
            // dengan atribut model. Rule `boolean` menerima 1/0/"1"/"0" tanpa mengubahnya.
            'aktif' => filter_var($data['aktif'] ?? true, FILTER_VALIDATE_BOOL),
            ...$this->extraPayload($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function changes(array $data): array
    {
        return [
            ...$data,
            ...array_key_exists('nama', $data) ? ['nama' => trim($data['nama'])] : [],
            ...array_key_exists('keterangan', $data) ? ['keterangan' => $this->trimmedOrNull($data['keterangan'])] : [],
            // Kolom tambahan dinormalkan lewat jalur yang sama seperti saat pembuatan,
            // supaya bentuk yang tersimpan tidak berbeda antara POST dan PATCH.
            ...$this->extraPayload($data),
        ];
    }

    /** Teks "0" adalah keterangan yang sah, jadi yang diperiksa kosong-atau-tidak, bukan truthiness. */
    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Yang menahan arsip adalah anak yang belum diarsipkan, terlepas dari penanda `aktif`. */
    private function unarchivedChild(MasterData $record): ?MasterChild
    {
        foreach ($this->childMasters() as $child) {
            $referenced = DB::table($child->table)
                ->where('tenant_id', $record->tenant_id)
                ->where($child->column, $record->getKey());
            if (Schema::hasColumn($child->table, 'deleted_at')) {
                $referenced->whereNull('deleted_at');
            }
            if ($referenced->exists()) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Payload dinormalkan lewat cast model sebelum dibandingkan. Tanpa ini, retry yang
     * sah dituduh konflik hanya karena beda tipe: kolom `decimal:2` mengembalikan
     * "1000.00" dari database sementara payload membawa float 1000.0, dan perbandingan
     * strict di bawah menganggapnya berbeda.
     *
     * @param  array<string, mixed>  $payload
     */
    private function replay(MasterData $record, array $payload): JsonResponse
    {
        $model = $this->model();
        $normalized = (new $model)->forceFill($payload)->only(array_keys($payload));

        if ($record->only(array_keys($payload)) !== $normalized) {
            return response()->json(['error' => [
                'code' => 'idempotency_conflict',
                'message' => 'Kunci permintaan sudah dipakai untuk data yang berbeda.',
            ]], 409);
        }

        return response()->json(['data' => $this->present($record)], 200, ['Idempotent-Replayed' => 'true']);
    }

    /** @return array<string, mixed> */
    private function present(MasterData $record): array
    {
        $data = [
            'id' => $record->getKey(),
            'kode' => $record->kode,
            'nama' => $record->nama,
            'keterangan' => $record->keterangan,
            'aktif' => $record->aktif,
        ];

        foreach ($this->parentMasters() as $parent) {
            $related = $record->loadMissing($parent->eagerLoad())->getRelation($parent->relation);
            $data[$parent->column] = $record->{$parent->column};
            $data[$parent->payloadKey()] = $related instanceof MasterData
                ? ['id' => $related->getKey(), 'kode' => $related->kode, 'nama' => $related->nama]
                : null;
        }

        return [
            ...$data,
            ...$this->extraPresent($record),
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    /**
     * Aturan validasi kolom tambahan milik satu master, di luar bentuk dasar
     * nama/keterangan/aktif/induk. Dipakai master yang membawa field sendiri seperti
     * group aset dan profil penyusutan, supaya subclass tidak perlu menimpa
     * writeRules() secara penuh dan kehilangan aturan induk.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function extraRules(string $tenantId, bool $creating): array
    {
        return [];
    }

    /**
     * Validasi yang baru dapat dilakukan setelah seluruh field master tervalidasi.
     * Controller turunan memakai ini untuk menjaga aturan yang bergantung pada data
     * transaksi atau kombinasi beberapa field.
     *
     * @param  array<string, mixed>  $data
     */
    protected function afterWriteValidation(array $data, string $tenantId, bool $creating, ?MasterData $record = null): void {}

    /**
     * Nilai kolom tambahan yang disimpan saat pembuatan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extraPayload(array $data): array
    {
        return [];
    }

    /**
     * Kolom tambahan yang disajikan pada respons.
     *
     * @return array<string, mixed>
     */
    protected function extraPresent(MasterData $record): array
    {
        return [];
    }

    /** Memperkaya query master tanpa menambah query per baris. */
    protected function prepareQuery(Builder $query, ?Request $request = null): Builder
    {
        return $query;
    }

    /** Master tertentu dapat meminta PATCH berjalan di dalam transaksi dengan row lock. */
    protected function updateUnderLock(): bool
    {
        return false;
    }
}
