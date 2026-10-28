<?php

namespace App\Foundation\PlanningYear\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property int $version */
#[DataClassification(DataClass::CustomerContent)]
final class PlanningYear extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'legal_entity_id', 'year', 'active', 'creation_key', 'creation_payload_hash'];

    protected $hidden = ['creation_key', 'creation_payload_hash'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }
}
