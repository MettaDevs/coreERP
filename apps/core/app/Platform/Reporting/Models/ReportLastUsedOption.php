<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Opsi dan filter terakhir seorang pengguna untuk satu laporan (K-24), padanan "Last used options and
 * filters" Business Central. Satu baris per pengguna per laporan, ditimpa setiap kali laporan dijalankan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $report_code
 * @property array<string, mixed> $parameters
 * @property ?string $format
 * @property ?string $layout_ref
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
#[DataClassification(DataClass::CustomerContent)]
class ReportLastUsedOption extends Model
{
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    protected $fillable = ['tenant_id', 'user_id', 'report_code', 'parameters', 'format', 'layout_ref'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'parameters' => 'array',
            'version' => 'integer',
        ];
    }
}
