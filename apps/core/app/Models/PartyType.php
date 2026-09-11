<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Klasifikasi Party standar platform, bukan data yang dikelola tenant. */
class PartyType extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';
}
