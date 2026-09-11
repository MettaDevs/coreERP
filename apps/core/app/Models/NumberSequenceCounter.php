<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id @property int $next_number */
class NumberSequenceCounter extends Model
{
    use HasUlids;

    protected $fillable = ['sequence_id', 'scope_key', 'period_key', 'next_number'];
}
