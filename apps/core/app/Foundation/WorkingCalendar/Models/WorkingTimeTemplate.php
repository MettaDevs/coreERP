<?php

namespace App\Foundation\WorkingCalendar\Models;

use App\Models\Organization;
use App\Models\Tenant;
use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class WorkingTimeTemplate extends Model
{
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'legal_entity_id');
    }

    /** @return HasMany<WorkingTimeLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkingTimeLine::class, 'working_time_template_id');
    }
}
