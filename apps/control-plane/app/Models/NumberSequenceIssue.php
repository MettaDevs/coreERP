<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id @property string $formatted_value */
class NumberSequenceIssue extends Model
{
    use HasUlids;

    protected $fillable = ['sequence_id', 'app_id', 'scope_key', 'period_key', 'numeric_value', 'formatted_value', 'idempotency_key', 'is_manual', 'issued_at'];

    protected function casts(): array
    {
        return ['is_manual' => 'boolean', 'issued_at' => 'datetime'];
    }
}
