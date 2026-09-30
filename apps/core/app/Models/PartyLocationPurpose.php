<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu kegunaan pada satu tautan party–tempat. Satu alamat dapat sekaligus alamat bisnis, kirim, dan tagih
 * tanpa dimasukkan tiga kali.
 *
 * @property string $party_location_id
 * @property string $purpose_code
 */
#[DataClassification(DataClass::CustomerContent)]
class PartyLocationPurpose extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'party_location_id', 'purpose_code'];
}
