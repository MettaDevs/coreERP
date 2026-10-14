<?php

namespace App\Platform\AddressBook\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DataClassification(DataClass::CustomerContent)]
class PartyRelationship extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'from_party_id', 'to_party_id', 'relationship_type_code', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }
}
