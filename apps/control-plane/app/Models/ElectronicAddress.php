<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kontak elektronik party: email, telepon, fax, atau URL. */
class ElectronicAddress extends Model
{
    use HasUlids;

    public const TYPES = ['email', 'phone', 'fax', 'url'];

    protected $fillable = ['tenant_id', 'party_id', 'type', 'value', 'purpose', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
