<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reference data global, bukan milik tenant. Kode ISO 3166-1 alpha-2. */
class CountryRegion extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'iso3', 'name'];
}
