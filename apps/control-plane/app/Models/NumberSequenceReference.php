<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property list<string> $allowed_scopes */
class NumberSequenceReference extends Model
{
    use HasUlids;

    protected $table = 'app_number_sequence_references';

    protected $fillable = ['app_id', 'code', 'name', 'default_prefix', 'allowed_scopes'];

    protected function casts(): array
    {
        return ['allowed_scopes' => 'array'];
    }

    /** @return BelongsTo<CoreApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CoreApp::class, 'app_id');
    }

    /** @return HasMany<TenantNumberSequence, $this> */
    public function sequences(): HasMany
    {
        return $this->hasMany(TenantNumberSequence::class, 'reference_id');
    }
}
