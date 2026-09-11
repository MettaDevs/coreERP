<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppEntryPoint extends Model
{
    protected $table = 'app_entry_points';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';
}
