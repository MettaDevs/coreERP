<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catatan satu query analitik yang sampai ke engine, ditulis {@see QueryLog}. Log ini dibaca operator dan
 * admin tenant untuk melihat query yang lambat, ditolak, atau gagal; ia bukan jejak audit perubahan.
 *
 * `query` adalah bentuk normal query dengan nilai saringan field data pribadi sudah disamarkan
 * (`[disamarkan]`), jadi isinya angka dan teks bisnis biasa. `principal` menunjuk keanggotaan, publikasi,
 * atau token yang menjalankannya. Diretensi lewat kebijakan `analytics_query_log`; penghapusan retensi
 * fisik, seperti log lain.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $principal
 * @property string $source
 * @property string $dataset_code
 * @property ?int $dataset_version
 * @property string $query_hash
 * @property array<string, mixed> $query
 * @property string $status
 * @property ?string $error_code
 * @property int $duration_ms
 * @property ?int $row_count
 * @property bool $truncated
 * @property bool $cached
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::SystemMetadata)]
class QueryLogEntry extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'principal' => DataClass::EndUserPseudonymousIdentifiers,
        'query' => DataClass::CustomerContent,
    ];

    protected $table = 'analytics_query_log';

    protected $fillable = [
        'tenant_id', 'principal', 'source', 'dataset_code', 'dataset_version', 'query_hash', 'query',
        'status', 'error_code', 'duration_ms', 'row_count', 'truncated', 'cached',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dataset_version' => 'integer',
            'query' => 'array',
            'duration_ms' => 'integer',
            'row_count' => 'integer',
            'truncated' => 'boolean',
            'cached' => 'boolean',
            'version' => 'integer',
        ];
    }
}
