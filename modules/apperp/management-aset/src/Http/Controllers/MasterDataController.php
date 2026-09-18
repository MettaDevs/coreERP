<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\GroupBukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\JenisAsetAtribut;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplateLine;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistVariableValue;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeDefault;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\master\TipeAtributNilai;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use Modules\Apperp\ManagementAset\Support\MasterParent;
use RuntimeException;

/**
 * Perilaku bersama seluruh master Management Aset: hak akses per resource, batas
 * tenant, idempotency, kode dari Number Sequence Core, induk rantai klasifikasi,
 * dan arsip yang tidak memutus referensi aktif.
 *
 * `TModel` adalah model master yang dipegang satu controller turunan. Tanda tangan PHP
 * di bawah tetap menyebut `MasterData` — kontravariansi melarang anak menyempitkannya —
 * sehingga tanpa parameter tipe ini analisa statis membaca setiap `$record` sebagai
 * kelas induk yang abstrak, lalu menolak kolom yang sebenarnya milik anak. Anak
 * menyebutkan modelnya lewat `@extends MasterDataController<GroupAset>`.
 *
 * @template TModel of MasterData
 */
abstract class MasterDataController extends Controller
{
    /**
     * ID app pada kode permission dan reference nomor. Nilai statis yang harus sama
     * dengan `app.yaml`; sengaja bukan konfigurasi runtime agar env tidak dapat
     * menggeser hak akses.
     */
    protected const APP_ID = 'management-aset';

    /**
     * Model pemilik tiap tabel yang dapat menjadi anak sebuah master.
     *
     * `MasterChild` menyebut anaknya dengan nama tabel, sedangkan penyaringan tenant baru
     * ikut berjalan bila query berangkat dari model. Peta ini yang menyambung keduanya, dan
     * ia dapat dibuang begitu `MasterChild` sendiri membawa nama kelas modelnya.
     *
     * @var array<string, class-string<Model>>
     */
    private const MODEL_ANAK = [
        'aset_m_buku_penyusutan' => BukuPenyusutan::class,
        'aset_m_group_aset' => GroupAset::class,
        'aset_m_group_buku_penyusutan' => GroupBukuPenyusutan::class,
        'aset_m_jenis_aset_atribut' => JenisAsetAtribut::class,
        'aset_m_lokasi_aset' => LokasiAset::class,
        'aset_m_maintenance_checklist_template_line' => MaintenanceChecklistTemplateLine::class,
        'aset_m_maintenance_checklist_variable_value' => MaintenanceChecklistVariableValue::class,
        'aset_m_maintenance_job_type_default' => MaintenanceJobTypeDefault::class,
        'aset_m_maintenance_job_type_variant' => MaintenanceJobTypeVariant::class,
        'aset_m_model_aset' => ModelAset::class,
        'aset_m_tipe_atribut_nilai' => TipeAtributNilai::class,
        'aset_tr_buku_aset' => BukuAset::class,
        'aset_tr_aset' => Aset::class,
    ];

    /** Slug resource pada route, kode permission, dan reference nomor. */
    abstract protected function resource(): string;

    /** @return class-string<TModel> */
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

        $query = $this->prepareQuery($this->masterQuery(), $request);
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

    public function store(Request $request, PenerbitNomorAset $numbers): JsonResponse
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

        if ($existing = $this->creationKeyQuery($creationKey)->first()) {
            return $this->replay($existing, $payload);
        }
        $this->afterWriteValidation($data, $tenantId, creating: true);

        try {
            // Penerbitan nomor dan penyimpanan record berada dalam **satu** transaksi.
            //
            // Selama penerbitan berjalan lewat HTTP, ia mustahil berada di dalam transaksi ini:
            // nomor sudah terbit di Core sementara penyimpanan batal, dan penghitung melompat
            // tanpa ada record yang memakainya. Lompatan itu yang harus dijelaskan ke pemeriksa.
            //
            // Sekarang keduanya berjalan pada koneksi yang sama, jadi keduanya batal bersama.
            // Ini keuntungan yang membenarkan seluruh pemindahan ke satu runtime.
            $record = DB::transaction(function () use ($numbers, $tenantId, $creationKey, $payload) {
                $kode = $numbers->issue(
                    static::APP_ID.'.'.$this->resource(),
                    $tenantId,
                    $this->resource().':'.$creationKey,
                );

                return $this->newQuery()->create([
                    'tenant_id' => $tenantId,
                    'creation_key' => $creationKey,
                    'kode' => $kode,
                    ...$payload,
                ]);
            });
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        } catch (QueryException $exception) {
            $existing = $this->creationKeyQuery($creationKey)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $payload);
        }

        return response()->json(['data' => $this->present($record)], 201, [
            // Alamatnya diturunkan dari permintaan yang sedang dilayani, bukan ditulis tangan.
            // Sampai 10 September 2026 baris ini menunjuk `/api/v1/<resource>/<id>` — alamat
            // modul waktu ia masih app tersendiri, dan alamat yang tidak ada lagi sejak rutenya
            // pindah ke `/api/modules/management-aset/v1/`. Klien yang mengikuti `Location`
            // sesudah membuat record mendarat di 404, dan tidak ada yang gagal karenanya karena
            // tidak ada yang memeriksa isi header ini.
            //
            // `$request->url()` adalah alamat koleksi yang baru saja dikirimi POST, jadi ia
            // tidak dapat menyimpang dari awalan rutenya — termasuk bila awalannya berubah lagi.
            'Location' => $request->url().'/'.$record->getKey(),
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

    /**
     * Builder master ini, dengan pilihan ikut memuat baris yang sudah diarsipkan.
     *
     * `setModel()` di akhir menyerahkan instance yang sama seperti yang baru dipakai
     * `newQuery()`, jadi saat berjalan ia tidak mengubah apa pun: model dan nama tabelnya
     * persis sama. Ia yang mengembalikan tipe anak pada builder ini. Larastan menyimpulkan
     * tipe `Model::newQuery()` dan `withTrashed()` dari kelas nyata pemanggilnya, dan pada
     * sebuah parameter tipe ia hanya melihat batas atasnya — `MasterData` — sehingga tanpa
     * langkah ini seluruh rantai query di bawah kehilangan kolom milik anak.
     *
     * @return Builder<TModel>
     */
    private function newQuery(bool $termasukArsip = false): Builder
    {
        $model = $this->model();
        $instance = new $model;
        $query = $instance->newQuery();
        if ($termasukArsip) {
            $query->withTrashed();
        }

        return $query->setModel($instance);
    }

    /** @return Builder<TModel> */
    private function masterQuery(): Builder
    {
        $query = $this->newQuery();
        foreach ($this->parentMasters() as $parent) {
            $query->with($parent->eagerLoad());
        }

        return $query;
    }

    /**
     * Unique (tenant_id, creation_key) juga mencakup record yang sudah diarsipkan, jadi
     * pencarian replay wajib menembus soft delete. Tanpa baris terarsip, retry dengan
     * kunci milik record yang sudah diarsipkan tidak menemukan apa pun, menerbitkan nomor
     * kedua, lalu menabrak unique index dan berakhir sebagai 500.
     *
     * @return Builder<TModel>
     */
    private function creationKeyQuery(string $creationKey): Builder
    {
        return $this->newQuery(termasukArsip: true)->where('creation_key', $creationKey);
    }

    /** @return TModel */
    private function find(Request $request, string $id, bool $lock = false): MasterData
    {
        $query = $this->prepareQuery($this->masterQuery(), $request);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    /**
     * Master yang menunjuk dirinya sendiri (contohnya lokasi) tidak boleh membentuk siklus.
     *
     * @param  TModel  $record
     * @param  array<string, mixed>  $data
     */
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
                // Induk yang sudah diarsipkan tetap menjadi mata rantai yang sah.
                // Menghentikan penelusuran di situ berarti siklus yang melewatinya lolos,
                // dan record itu masih ditunjuk anaknya.
                $parentId = $this->newQuery(termasukArsip: true)->whereKey($parentId)->value($parent->column);
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

    /**
     * Yang menahan arsip adalah anak yang belum diarsipkan, terlepas dari penanda `aktif`.
     *
     * @param  TModel  $record
     */
    private function unarchivedChild(MasterData $record): ?MasterChild
    {
        foreach ($this->childMasters() as $child) {
            $model = self::MODEL_ANAK[$child->table] ?? throw new RuntimeException(
                'Tabel anak '.$child->table.' belum punya model pada '.static::class.'::MODEL_ANAK.'
            );
            // Tanpa pemeriksaan kolom `deleted_at` lagi: model yang memakai soft delete
            // menyembunyikan baris terarsip sendiri, dan tabel yang tidak mengenalnya
            // memang tidak punya apa pun untuk disaring.
            if ($model::query()->where($child->column, $record->getKey())->exists()) {
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
     * @param  TModel  $record
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

    /**
     * @param  TModel  $record
     * @return array<string, mixed>
     */
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
     * @return array<string, list<mixed>>
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
     * @param  TModel|null  $record
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
     * @param  TModel  $record
     * @return array<string, mixed>
     */
    protected function extraPresent(MasterData $record): array
    {
        return [];
    }

    /**
     * Memperkaya query master tanpa menambah query per baris.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
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
