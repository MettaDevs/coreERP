<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pernyataan satu dataset. Nama kolom tabel dasar ditulis tanpa awalan tabel, dan kolom tabel yang
 * di-join ditulis `alias.kolom`. Kunci field dan measure berbentuk `snake_case` (paling panjang 64
 * karakter), unik di dalam dataset, dan menjadi janji kepada widget tenant: menggantinya butuh versi
 * definisi baru beserta peta nama lama.
 *
 * Pembangun ini hanya mengumpulkan pernyataan. Penafsirannya — membaca katalog model, memeriksa
 * kolom, memasang klasifikasi — dikerjakan Core, satu tempat untuk semua module, supaya kelas
 * kontrak tetap tipis dan module tidak dapat melewatinya. Aturan lengkapnya ada di
 * `docs/todo/analitik/model-semantik.md` bagian *Yang diperiksa DatasetValidator*.
 */
final class DatasetDefinition
{
    /** @var array<string, array{caption: string, aggregate: Aggregate, field: ?string, format: MeasureFormat, currency: ?string, unit: ?string, where: array<string, list<string|int|bool|null>|string|int|bool|null>}> */
    private array $measures = [];

    /** @var list<string> */
    private array $times = [];

    private ?string $defaultTime = null;

    /** @var class-string|null */
    private ?string $model = null;

    private ?string $permission = null;

    /** @var array{code: string, legal_entity: string, operating_unit: ?string}|null */
    private ?array $policy = null;

    /** @var array{only: list<string>, except: list<string>}|null */
    private ?array $fromModel = null;

    /** @var array<string, array{caption: string, type: FieldType, column: ?string, options: array<string, string>, classification: ?DataClass}> */
    private array $fields = [];

    /** @var array<string, array{model: class-string, localColumn: string, foreignColumn: string, includeArchived: bool}> */
    private array $joins = [];

    /** @var array<string, array{model: class-string, label: string, code: ?string}> */
    private array $references = [];

    /** @var array<string, SharedDimension> */
    private array $shared = [];

    /** @var (Closure(): Builder<Model>)|null */
    private ?Closure $source = null;

    private ?string $description = null;

    private ?string $recordRoute = null;

    private int $version = 1;

    /** @var array<string, string> */
    private array $renamed = [];

    private function __construct(
        public readonly string $code,
        public readonly string $caption,
    ) {}

    /** `$code` berawalan id module, misalnya `management-aset.asset-register`. `$caption` tampil di layar. */
    public static function make(string $code, string $caption): self
    {
        return new self($code, $caption);
    }

    /** Penjelasan singkat untuk katalog dataset di layar. */
    public function description(string $text): self
    {
        $this->description = $text;

        return $this;
    }

    /**
     * Tabel dasar lewat model module. Model wajib memakai `BelongsToTenant`, jadi penyaringan tenant
     * dan baris terarsip ikut dari model, tidak ditulis ulang. Tabel dasar tidak pernah diberi alias.
     *
     * @param  class-string  $model
     */
    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Sumber berupa query module, untuk bentuk yang tidak dapat dinyatakan sebagai tabel ditambah join
     * (misalnya nilai buku terakhir per aset). Closure memulangkan query Eloquent yang disusun dari model
     * module ber-`BelongsToTenant`, tanpa saringan milik pengguna mana pun, dan memilih `tenant_id`:
     * Core menyaring tenant sekali lagi di query luar, karena subquery tidak membawa scope ke luar.
     *
     * Field wajib dinyatakan satu per satu lewat {@see self::field()}, beserta klasifikasinya, karena
     * kolom subquery tidak punya katalog model untuk dibaca. Closure tidak boleh membaca database.
     *
     * @param  Closure(): Builder<Model>  $source
     */
    public function fromQuery(Closure $source): self
    {
        $this->source = $source;

        return $this;
    }

    /** Permission baca resource yang sudah ada di manifest module (KA-15), misalnya `management-aset.aset.read`. */
    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * Kebijakan data yang menjaga resource ini dan kolom yang menegakkannya, persis kolom yang dipakai
     * endpoint daftar module. Tanpa `$operatingUnit`, hanya legal entity yang dicocokkan — sama dengan
     * `OrganizationScope::legalEntityQuery()` untuk record tanpa unit kerja. Kolom tabel yang di-join
     * ditulis `alias.kolom`.
     */
    public function dataPolicy(string $code, string $legalEntity, ?string $operatingUnit = null): self
    {
        $this->policy = ['code' => $code, 'legal_entity' => $legalEntity, 'operating_unit' => $operatingUnit];

        return $this;
    }

    /**
     * Seluruh kolom katalog filter tambahan K-30 model dasar menjadi field: nama tampilan dari
     * `FIELD_CAPTIONS`, pilihan dari `FIELD_OPTIONS`, tipe dari database, kolom `FIELD_HIDDEN` dan
     * `AccountData` tidak ikut.
     *
     * @param  list<string>  $only
     * @param  list<string>  $except
     */
    public function fieldsFromModel(array $only = [], array $except = []): self
    {
        $this->fromModel = ['only' => $only, 'except' => $except];

        return $this;
    }

    /**
     * Field yang tidak ada di katalog model, berasal dari tabel yang di-join (`$column` = `alias.kolom`),
     * atau kolom dataset bersumber query. Tanpa `$column`, kolomnya kolom tabel dasar bernama `$key`.
     * Tanpa `$classification`, klasifikasinya dibaca dari model pemilik kolom; dataset bersumber query
     * wajib menyebutnya.
     *
     * @param  array<string, string>  $options  nilai => label, untuk `FieldType::Option`
     */
    public function field(string $key, string $caption, FieldType $type, ?string $column = null, array $options = [], ?DataClass $classification = null): self
    {
        $this->fields[$key] = compact('caption', 'type', 'column', 'options', 'classification');

        return $this;
    }

    /**
     * Field rujukan ke master milik module yang sama, dengan sumber labelnya. Join label membawa baris
     * terarsip juga, supaya aset yang group-nya sudah diarsipkan tetap bernama. Field yang belum ada
     * dibuat dari kolom tabel dasar bernama `$key`, walau kolom itu tersembunyi dari katalog filter.
     *
     * @param  class-string  $model
     */
    public function reference(string $key, string $model, string $label = 'nama', ?string $code = 'kode'): self
    {
        $this->references[$key] = compact('model', 'label', 'code');

        return $this;
    }

    /**
     * Field yang menunjuk dimensi milik Core atau Foundation; labelnya diterjemahkan Core. Field yang
     * belum ada dibuat dari kolom tabel dasar bernama `$key`, walau kolom itu tersembunyi dari katalog
     * filter.
     */
    public function shared(string $key, SharedDimension $dimension): self
    {
        $this->shared[$key] = $dimension;

        return $this;
    }

    /**
     * Tabel module yang sama yang di-join ke tabel dasar. Join selalu membawa `tenant_id` yang sama, dan
     * bawaannya hanya baris yang belum diarsipkan. `$localColumn` boleh kolom tabel dasar atau
     * `alias.kolom` join yang dinyatakan lebih dulu.
     *
     * @param  class-string  $model
     */
    public function join(string $alias, string $model, string $localColumn, string $foreignColumn = 'id', bool $includeArchived = false): self
    {
        $this->joins[$alias] = compact('model', 'localColumn', 'foreignColumn', 'includeArchived');

        return $this;
    }

    /**
     * Nilai yang sah dihitung. `$field` adalah kunci field, kolom tabel dasar, atau `alias.kolom`.
     * `$where` adalah saringan tetap measure, hanya kesamaan dan daftar nilai pada field pilihan,
     * ya/tidak, atau rujukan (`['lifecycle_state' => ['disposed']]`), dan dikompilasi menjadi
     * `FILTER (WHERE …)`.
     *
     * @param  array<string, list<string|int|bool|null>|string|int|bool|null>  $where
     */
    public function measure(
        string $key,
        string $caption,
        Aggregate $aggregate,
        ?string $field = null,
        MeasureFormat $format = MeasureFormat::Number,
        ?string $currency = null,
        ?string $unit = null,
        array $where = [],
    ): self {
        $this->measures[$key] = compact('caption', 'aggregate', 'field', 'format', 'currency', 'unit', 'where');

        return $this;
    }

    /** Field tanggal yang dapat dipakai untuk rentang dan pengelompokan waktu. Boleh lebih dari satu. */
    public function time(string $field, bool $default = false): self
    {
        $this->times[] = $field;
        if ($default) {
            $this->defaultTime = $field;
        }

        return $this;
    }

    /** Alamat layar record, dengan `{id}` diganti id baris. Dipakai drill-through (fase 2). */
    public function recordRoute(string $template): self
    {
        $this->recordRoute = $template;

        return $this;
    }

    /**
     * Versi definisi. Naikkan setiap kali kunci field atau measure dihapus atau diganti; `$renamed`
     * memetakan kunci lama ke kunci baru supaya widget tenant tetap berjalan.
     *
     * @param  array<string, string>  $renamed
     */
    public function version(int $version, array $renamed = []): self
    {
        $this->version = $version;
        $this->renamed = $renamed;

        return $this;
    }

    /**
     * Bentuk mentah untuk registry Core. Bukan API module.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
