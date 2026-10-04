<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu hasil query analitik yang sudah dihitung, disimpan di database tenant (KA-18). Dibaca dan ditulis
 * hanya oleh {@see QueryCache}, yang juga menyusun kuncinya.
 *
 * `payload` adalah JSON hasil yang dikompres gzip. Hasil principal yang berhak data pribadi dapat memuat
 * nama orang, jadi kolom itu diklasifikasi sebagai data pribadi walau kebanyakan isinya angka bisnis.
 * Tidak ada `deleted_at`: baris kedaluwarsa dihapus fisik, karena ia salinan hasil hitung, bukan data bisnis.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cache_key
 * @property string $dataset_code
 * @property resource|string $payload
 * @property int $size_bytes
 * @property Carbon $expires_at
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class QueryCacheEntry extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'cache_key' => DataClass::SystemMetadata,
        'dataset_code' => DataClass::SystemMetadata,
        'payload' => DataClass::EndUserIdentifiableInformation,
        'size_bytes' => DataClass::SystemMetadata,
        'expires_at' => DataClass::SystemMetadata,
    ];

    protected $table = 'analytics_query_cache';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
