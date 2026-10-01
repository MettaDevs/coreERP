<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Models;

use App\Platform\Identity\Models\User;
use App\Platform\Reporting\Support\RelativeDates;
use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Preset bernama berisi filter satu laporan (K-25), padanan setelan laporan bernama di page 1560 Report
 * Settings Business Central. Pemiliknya satu pengguna; preset `shared` terlihat oleh setiap pengguna tenant
 * yang boleh menjalankan laporannya.
 *
 * Nilai tanggal pada `parameters` boleh berupa token relatif (`@this_month.start`) yang diterjemahkan saat
 * preset dipakai, lihat {@see RelativeDates}.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $report_code
 * @property string $name
 * @property bool $shared
 * @property array<string, mixed> $parameters
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?User $user
 */
#[DataClassification(DataClass::CustomerContent)]
class ReportPreset extends Model
{
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'user_id' => DataClass::EndUserPseudonymousIdentifiers,
        'name' => DataClass::CustomerContent,
    ];

    protected $fillable = ['tenant_id', 'user_id', 'report_code', 'name', 'shared', 'parameters'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'shared' => 'boolean',
            'parameters' => 'array',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
