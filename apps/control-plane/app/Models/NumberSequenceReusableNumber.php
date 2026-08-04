<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $sequence_id @property string $scope_key @property string $period_key @property int $numeric_value */
class NumberSequenceReusableNumber extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['sequence_id', 'scope_key', 'period_key', 'numeric_value'];
}
