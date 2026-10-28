<?php

namespace App\Foundation\FundingSource\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DataClassification(DataClass::CustomerContent)]
final class FundingSource extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'name', 'description', 'active', 'creation_key', 'creation_payload_hash'];

    protected $hidden = ['code', 'creation_key', 'creation_payload_hash'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }
}
