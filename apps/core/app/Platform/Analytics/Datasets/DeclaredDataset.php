<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dataset sebagaimana dinyatakan module, sesudah lolos pemeriksaan yang tidak butuh database
 * ({@see DatasetValidator::declare()}). Sama untuk setiap tenant dan setiap database, jadi registry
 * menyimpannya sekali per proses. Pemeriksaan terhadap tabel sungguhan — kolom ada, tipe, klasifikasi —
 * baru terjadi di {@see DatasetValidator::compile()}, per database.
 *
 * Nama kolom masih seperti ditulis module: kolom tabel dasar tanpa awalan, kolom join `alias.kolom`.
 */
final readonly class DeclaredDataset
{
    /**
     * @param  class-string<Model>  $model  model dasar; untuk dataset bersumber query, model query sumbernya
     * @param  (Closure(): Builder<Model>)|null  $source
     * @param  array{code: string, legal_entity: string, operating_unit: ?string}|null  $policy
     * @param  array{only: list<string>, except: list<string>}|null  $fromModel
     * @param  array<string, array{caption: string, type: FieldType, column: ?string, options: array<string, string>, classification: ?DataClass}>  $fields
     * @param  array<string, array{model: class-string<Model>, local: string, foreign: string, include_archived: bool}>  $joins
     * @param  array<string, array{model: class-string<Model>, label: string, code: ?string}>  $references
     * @param  array<string, SharedDimension>  $shared
     * @param  array<string, CompiledMeasure>  $measures
     * @param  list<string>  $times
     * @param  array<string, list<string>>  $hierarchies
     * @param  array<string, string>  $renamed
     */
    public function __construct(
        public string $code,
        public string $caption,
        public string $moduleId,
        public ?string $description,
        public string $model,
        public ?Closure $source,
        public string $permission,
        public ?array $policy,
        public ?array $fromModel,
        public array $fields,
        public array $joins,
        public array $references,
        public array $shared,
        public array $measures,
        public array $times,
        public array $hierarchies,
        public ?string $defaultTime,
        public ?string $recordRoute,
        public int $version,
        public array $renamed,
    ) {}
}
