<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\FilterField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Dataset yang sudah dibaca registry dan siap dipakai compiler: field beserta kolom berkualifikasinya,
 * measure, kebijakan data, dan field waktu.
 *
 * Nama method di kelas ini dipakai bersama oleh compiler (area 3), keamanan baca (area 4), dan API
 * layar (area 6); daftarnya ada di `docs/todo/analitik/arsitektur.md` bagian *CompiledDataset*.
 * Menambah method boleh; mengganti yang ada berarti memperbarui halaman itu dalam pull request yang
 * sama.
 *
 * Tabel dasar tidak pernah diberi alias: `TenantScope` disisipkan dengan nama tabel sebenarnya, jadi
 * kolom selalu dikualifikasi dengan nama tabel itu.
 */
final readonly class CompiledDataset
{
    /**
     * @param  class-string<Model>  $model
     * @param  array{code: string, legal_entity: string, operating_unit: ?string}|null  $policy
     * @param  array<string, FilterField>  $fields
     * @param  array<string, CompiledMeasure>  $measures
     * @param  list<string>  $times
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
    ) {}

    /**
     * Query dasar lewat model module, sehingga penyaringan tenant (`TenantScope`) dan baris terarsip
     * (`SoftDeletes`) ikut dari model. Scope model baru dipasang saat query dijalankan, jadi pemanggil
     * wajib menjalankannya di dalam `TenantRunner::runFor()`.
     *
     * @return Builder<Model>
     */
    public function baseQuery(): Builder
    {
        return $this->model::query();
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
     * Kolom berkualifikasi untuk kunci field atau nama kolom tabel dasar, misalnya kolom mata uang atau
     * kolom kebijakan yang tidak ditawarkan sebagai field. Hanya nama yang lolos pemeriksaan pengenal
     * (huruf kecil, angka, garis bawah) yang dipulangkan; pemanggil membungkusnya dengan `wrap()`.
     */
    public function qualified(string $name): string
    {
        if (isset($this->fields[$name])) {
            return $this->fields[$name]->column;
        }

        if (! DatasetRegistry::isIdentifier($name)) {
            throw new LogicException("Kolom `{$name}` bukan nama kolom yang sah untuk dataset `{$this->code}`.");
        }

        return $this->table.'.'.$name;
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
}
