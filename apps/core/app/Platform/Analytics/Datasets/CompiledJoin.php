<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu join data yang dinyatakan dataset, ke tabel module yang sama. Kolomnya sudah berkualifikasi:
 * `localColumn` pada tabel dasar (atau join sebelumnya), `foreignColumn` pada alias join ini.
 *
 * Yang memasangnya compiler (area 3, `JoinPlanner`), hanya bila alias ini disebut query, dengan dua
 * syarat tetap: `alias.tenant_id` sama dengan `tenant_id` tabel dasar, dan `alias.deleted_at IS NULL`
 * bila tabelnya berarsip (`archivable`) dan baris terarsip tidak diminta.
 */
final readonly class CompiledJoin
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $alias,
        public string $model,
        public string $table,
        public string $localColumn,
        public string $foreignColumn,
        public bool $includeArchived,
        public bool $archivable,
    ) {}
}
