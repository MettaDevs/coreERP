<?php

namespace App\Platform\AddressBook\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tautan satu party ke satu tempat, padanan `DirPartyLocation` Dynamics 365. Kegunaannya disimpan di
 * {@see PartyLocationPurpose}, dan satu party paling banyak punya satu tautan utama — dijaga partial unique
 * index di antara tautan yang belum diarsipkan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $party_id
 * @property string $location_id
 * @property bool $is_primary
 * @property-read Location $location
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class PartyLocation extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'party_id', 'location_id', 'is_primary', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return HasMany<PartyLocationPurpose, $this> */
    public function purposes(): HasMany
    {
        return $this->hasMany(PartyLocationPurpose::class, 'party_location_id');
    }
}
