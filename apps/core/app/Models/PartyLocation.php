<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu lokasi milik party. Kegunaannya dinyatakan `purpose`, dan satu party
 * paling banyak punya satu lokasi utama — dijaga partial unique index.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $party_id
 * @property bool $is_primary
 */
class PartyLocation extends Model
{
    use HasUlids;

    public const PURPOSES = ['business', 'delivery', 'invoice', 'payment', 'home'];

    protected $fillable = ['tenant_id', 'party_id', 'name', 'purpose', 'is_primary', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /** @return HasOne<PostalAddress, $this> */
    public function postalAddress(): HasOne
    {
        return $this->hasOne(PostalAddress::class, 'location_id');
    }
}
