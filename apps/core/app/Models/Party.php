<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Satu pihak yang berurusan dengan tenant: orang atau organisasi.
 *
 * Party adalah identitas beserta alamat dan kontaknya. Perannya — pelanggan,
 * pemasok, pegawai — dimiliki app dan berlaku per legal entity.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $name
 */
class Party extends Model
{
    use HasUlids;

    public const TYPES = ['person', 'organization'];

    protected $fillable = ['tenant_id', 'type', 'name', 'search_name', 'status'];

    /** Bentuk nama yang dinormalisasi untuk pencarian dan deteksi kembar. */
    public static function searchName(string $name): string
    {
        return Str::squish(Str::lower($name));
    }

    /** @return HasMany<PartyLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(PartyLocation::class, 'party_id');
    }

    /** @return HasMany<ElectronicAddress, $this> */
    public function electronicAddresses(): HasMany
    {
        return $this->hasMany(ElectronicAddress::class, 'party_id');
    }

    /** @return HasMany<PartyRoleRegistration, $this> */
    public function roleRegistrations(): HasMany
    {
        return $this->hasMany(PartyRoleRegistration::class, 'party_id');
    }
}
