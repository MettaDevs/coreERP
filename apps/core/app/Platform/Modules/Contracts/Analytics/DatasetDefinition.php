<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Pernyataan satu dataset. Semua nama kolom ditulis tanpa awalan tabel. Kunci field dan measure
 * berbentuk `snake_case`, unik di dalam dataset, dan menjadi janji kepada widget tenant: menggantinya
 * butuh versi definisi baru beserta peta nama lama.
 *
 * Pembangun ini hanya mengumpulkan pernyataan. Penafsirannya — membaca katalog model, memeriksa
 * kolom, memasang klasifikasi — dikerjakan Core, satu tempat untuk semua module, supaya kelas
 * kontrak tetap tipis dan module tidak dapat melewatinya.
 *
 * Isi sekarang adalah subset kerangka berjalan (area 0 engine analitik): `model()`, `permission()`,
 * `dataPolicy()`, `fieldsFromModel()`, `measure()`, dan `time()`. Bentuk lengkapnya — join, rujukan,
 * dimensi bersama, sumber query, versi — ada di `docs/todo/analitik/model-semantik.md` dan
 * ditambahkan area 1 tanpa mengubah method yang sudah ada.
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

    private function __construct(
        public readonly string $code,
        public readonly string $caption,
    ) {}

    /** `$code` berawalan id module, misalnya `management-aset.asset-register`. `$caption` tampil di layar. */
    public static function make(string $code, string $caption): self
    {
        return new self($code, $caption);
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

    /** Permission baca resource yang sudah ada di manifest module (KA-15), misalnya `management-aset.aset.read`. */
    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * Kebijakan data yang menjaga resource ini dan kolom yang menegakkannya, persis kolom yang dipakai
     * endpoint daftar module. Tanpa `$operatingUnit`, hanya legal entity yang dicocokkan — sama dengan
     * `OrganizationScope::legalEntityQuery()` untuk record tanpa unit kerja.
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
     * Nilai yang sah dihitung. `$where` adalah saringan tetap measure, hanya kesamaan dan daftar nilai
     * pada field pilihan, ya/tidak, atau rujukan (`['lifecycle_state' => ['disposed']]`), dan dikompilasi
     * menjadi `FILTER (WHERE …)`.
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

    /** Field tanggal yang dapat dipakai untuk rentang dan pengelompokan waktu. */
    public function time(string $field, bool $default = false): self
    {
        $this->times[] = $field;
        if ($default) {
            $this->defaultTime = $field;
        }

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
