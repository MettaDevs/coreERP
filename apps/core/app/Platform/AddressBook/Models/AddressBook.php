<?php

namespace App\Platform\AddressBook\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DataClassification(DataClass::CustomerContent)]
class AddressBook extends Model
{
    use HasUlids;
    use SoftDeletes;

    /** Nama kelompok, bukan nama orang atau perusahaan. */
    public const COLUMN_CLASSIFICATION = ['name' => DataClass::CustomerContent];

    protected $fillable = ['tenant_id', 'name'];
}
