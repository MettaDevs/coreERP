<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu tempat, padanan `LogisticsLocation` Dynamics 365. Tidak punya kolom party: party menempel lewat
 * {@see PartyLocation}, sehingga satu gedung dapat dipakai beberapa pihak dan mengubah alamatnya sekali
 * mengubahnya bagi semua. Melepas tautan tidak membuang tempatnya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 */
class Location extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name'];

    /** @return HasOne<PostalAddress, $this> */
    public function postalAddress(): HasOne
    {
        return $this->hasOne(PostalAddress::class, 'location_id');
    }

    /** @return HasMany<ElectronicAddress, $this> */
    public function electronicAddresses(): HasMany
    {
        return $this->hasMany(ElectronicAddress::class, 'location_id');
    }

    /** @return HasMany<PartyLocation, $this> */
    public function partyLinks(): HasMany
    {
        return $this->hasMany(PartyLocation::class, 'location_id');
    }
}
