<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FilterField;
use App\Platform\Modules\Support\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Dataset yang sudah diperiksa terhadap database dan siap dipakai compiler: field beserta kolom
 * berkualifikasinya, klasifikasi dan tipe database tiap field, rujukan berlabel, dimensi bersama, join,
 * measure, kebijakan data, dan field waktu.
 *
 * Nama method di kelas ini dipakai bersama oleh model query (area 2), compiler (area 3), keamanan baca
 * (area 4), dan API layar (area 6); daftarnya ada di `docs/todo/analitik/arsitektur.md` bagian
 * *CompiledDataset*. Menambah method boleh; mengganti yang ada berarti memperbarui halaman itu dalam
 * pull request yang sama.
 *
 * Tabel dasar tidak pernah diberi alias: `TenantScope` disisipkan dengan nama tabel sebenarnya, jadi
 * kolom selalu dikualifikasi dengan nama tabel itu. Dataset bersumber query adalah pengecualiannya:
 * subquery-nya diberi alias {@see self::SOURCE_ALIAS}, dan `TenantScope` sudah terpasang di dalamnya.
 *
 * Tidak memuat data tenant apa pun — hanya definisi dan skema — sehingga registry boleh menyimpannya
 * selama proses hidup, termasuk di pekerja FrankenPHP yang melayani banyak tenant.
 */
final readonly class CompiledDataset
{
    /** Alias subquery dataset bersumber query; juga awalan kolomnya. */
    public const SOURCE_ALIAS = 'base';

    /**
     * @param  class-string<Model>  $model
     * @param  array{code: string, legal_entity: string, operating_unit: ?string}|null  $policy
     * @param  array<string, FilterField>  $fields
     * @param  array<string, CompiledMeasure>  $measures
     * @param  list<string>  $times
     * @param  array<string, list<string>>  $hierarchies
     * @param  array<string, string>  $renamed  kunci lama => kunci baru
     * @param  array<string, DataClass>  $classifications  per kunci field
     * @param  array<string, string>  $columnTypes  nama tipe PostgreSQL kolom tiap field (`int4`, `date`, …)
     * @param  array<string, SharedDimension>  $sharedDimensions  per kunci field
     * @param  array<string, CompiledReference>  $references  per kunci field
     * @param  array<string, CompiledJoin>  $joins  per alias
     * @param  (Closure(): Builder<Model>)|null  $source
     */
    public function __construct(
        public string $code,
        public string $caption,
        public string $moduleId,
        public int $version,
        public string $model,
        public string $table,
        public string $permission,
        public ?array $policy,
        private array $fields,
        private array $measures,
        private array $times,
        private ?string $defaultTime,
        public ?string $description = null,
        public ?string $recordRoute = null,
        private array $renamed = [],
        private array $classifications = [],
        private array $columnTypes = [],
        private array $sharedDimensions = [],
        private array $references = [],
        private array $joins = [],
        private ?Closure $source = null,
        private string $hash = '',
        private array $hierarchies = [],
    ) {}

    /**
     * Query dasar lewat model module, sehingga penyaringan tenant (`TenantScope`) dan baris terarsip
     * (`SoftDeletes`) ikut dari model. Scope model baru dipasang saat query dijalankan, jadi pemanggil
     * wajib menjalankannya di dalam `TenantRunner::runFor()`.
     *
     * Dataset bersumber query: `SELECT … FROM (<query sumber>) AS base WHERE base.tenant_id = ?`. Scope
     * tenant query sumber terpasang saat subquery disusun di sini, dan saringan luar adalah lapis kedua
     * yang tetap menyaring bila query sumber kehilangan scope-nya. Keduanya membaca tenant aktif yang
     * sama, dan keduanya gagal tertutup bila tenant belum terikat.
     *
     * @return Builder<Model>
     */
    public function baseQuery(): Builder
    {
        if ($this->source === null) {
            return $this->model::query();
        }

        $builder = (new $this->model)->newQueryWithoutScopes();
        $builder->fromSub(($this->source)(), self::SOURCE_ALIAS)
            ->where(self::SOURCE_ALIAS.'.tenant_id', TenantScope::activeTenant());

        return $builder;
    }

    /** Dataset bersumber query (`fromQuery()`), bukan tabel model. */
    public function isQuerySource(): bool
    {
        return $this->source !== null;
    }

    /** @return array<string, FilterField> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function hasField(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /** Field untuk `FieldFilterExpression`; kunci yang tidak dikenal ditolak lebih dulu oleh validator query. */
    public function filterField(string $key): FilterField
    {
        return $this->fields[$key] ?? throw new LogicException("Field `{$key}` tidak ada di dataset `{$this->code}`.");
    }

    /** Klasifikasi data kolom field, untuk gerbang data pribadi. Tidak pernah `AccountData` atau belum diklasifikasi. */
    public function classification(string $key): DataClass
    {
        return $this->classifications[$key] ?? throw new LogicException("Field `{$key}` tidak ada di dataset `{$this->code}`.");
    }

    /** Nama tipe PostgreSQL kolom field (`int4`, `numeric`, `varchar`, `date`, `timestamp`, `timestamptz`, …). */
    public function columnType(string $key): string
    {
        return $this->columnTypes[$key] ?? throw new LogicException("Field `{$key}` tidak ada di dataset `{$this->code}`.");
    }

    /**
     * Jenis kolom field waktu, yang menentukan SQL ember waktunya: `date` tidak dikonversi, `timestamp`
     * berisi UTC, `timestamptz` membawa zonanya sendiri.
     *
     * @return 'date'|'timestamp'|'timestamptz'
     */
    public function timeType(string $key): string
    {
        $type = in_array($key, $this->times, true) ? ($this->columnTypes[$key] ?? null) : null;

        return match ($type) {
            'date', 'timestamp', 'timestamptz' => $type,
            default => throw new LogicException("Field `{$key}` bukan field waktu dataset `{$this->code}`."),
        };
    }

    /** Dimensi bersama yang ditunjuk field ini, atau null. Labelnya dari `SharedDimensionRegistry`, sesudah query. */
    public function sharedDimension(string $key): ?SharedDimension
    {
        return $this->sharedDimensions[$key] ?? null;
    }

    /** Sumber label rujukan module untuk field ini, atau null. */
    public function reference(string $key): ?CompiledReference
    {
        return $this->references[$key] ?? null;
    }

    /**
     * Kolom label yang ikut dipilih dan dikelompokkan bersama field rujukan, berkualifikasi alias join
     * labelnya: `['label' => 'r0.nama', 'code' => 'r0.kode']`. Kosong untuk field selain rujukan module;
     * label pilihan dan dimensi bersama diterjemahkan sesudah query, bukan di SQL.
     *
     * @return array<string, string>
     */
    public function labelColumnsFor(string $key): array
    {
        $reference = $this->references[$key] ?? null;
        if ($reference === null) {
            return [];
        }

        return array_filter(['label' => $reference->labelColumn, 'code' => $reference->codeColumn], static fn (?string $column): bool => $column !== null);
    }

    /** @return array<string, CompiledJoin> join data per alias, dalam urutan pernyataannya */
    public function joins(): array
    {
        return $this->joins;
    }

    /** @return array<string, CompiledMeasure> */
    public function measures(): array
    {
        return $this->measures;
    }

    public function hasMeasure(string $key): bool
    {
        return isset($this->measures[$key]);
    }

    public function measure(string $key): CompiledMeasure
    {
        return $this->measures[$key] ?? throw new LogicException("Measure `{$key}` tidak ada di dataset `{$this->code}`.");
    }

    /**
     * Kolom berkualifikasi untuk:
     *
     * - kunci field — kolom field itu;
     * - nama kolom tabel dasar, misalnya kolom mata uang atau kolom kebijakan yang tidak ditawarkan sebagai
     *   field — `<tabel>.<kolom>`;
     * - `alias.kolom` join yang dinyatakan dataset, atau `<tabel>.<kolom>` yang sudah berkualifikasi —
     *   dipulangkan apa adanya.
     *
     * Hanya nama yang lolos pemeriksaan pengenal (huruf kecil, angka, garis bawah) yang dipulangkan;
     * pemanggil membungkusnya dengan `wrap()`. Nama kolom sudah diperiksa ada di tabelnya oleh
     * `DatasetValidator`, kecuali nama tabel dasar polos yang tidak dinyatakan di mana pun.
     */
    public function qualified(string $name): string
    {
        if (isset($this->fields[$name])) {
            return $this->fields[$name]->column;
        }

        $parts = explode('.', $name);
        $valid = match (count($parts)) {
            1 => DatasetRegistry::isIdentifier($name),
            2 => ($parts[0] === $this->table || isset($this->joins[$parts[0]])) && DatasetRegistry::isIdentifier($parts[1]),
            default => false,
        };
        if (! $valid) {
            throw new LogicException("Kolom `{$name}` bukan nama kolom yang sah untuk dataset `{$this->code}`.");
        }

        return count($parts) === 2 ? $name : $this->table.'.'.$name;
    }

    /** @return list<string> */
    public function times(): array
    {
        return $this->times;
    }

    public function defaultTime(): ?string
    {
        return $this->defaultTime;
    }

    /** @return array<string, list<string>> */
    public function hierarchies(): array
    {
        return $this->hierarchies;
    }

    /**
     * Peta kunci lama ke kunci baru dari `version()`, untuk membaca widget yang disimpan dengan versi lama.
     *
     * @return array<string, string>
     */
    public function renamed(): array
    {
        return $this->renamed;
    }

    /**
     * Sidik jari definisi sesudah dibaca terhadap database ini (64 karakter heksadesimal sha256), untuk
     * kunci cache hasil: definisi atau skema yang berubah membuat hasil lama tidak terpakai lagi.
     */
    public function hash(): string
    {
        return $this->hash;
    }
}
