<?php

namespace App\Platform\AddressBook\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DataClassification(DataClass::CustomerContent)]
class PartyAddressBook extends Model
{
    use HasUlids;
    use SoftDeletes;

    public const COLUMN_CLASSIFICATION = ['address_book_id' => DataClass::CustomerContent];

    protected $fillable = ['tenant_id', 'party_id', 'address_book_id'];
}
