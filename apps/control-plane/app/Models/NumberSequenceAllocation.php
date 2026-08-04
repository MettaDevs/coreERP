<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id @property int $first_number @property int $last_number @property int $next_number */
class NumberSequenceAllocation extends Model
{
    use HasUlids;

    protected $fillable = ['sequence_id', 'scope_key', 'period_key', 'first_number', 'last_number', 'next_number'];
}
