<?php

namespace App\Foundation\AddressBook\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
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
#[DataClassification(DataClass::CustomerContent)]
class Party extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::EndUserIdentifiableInformation,
        'search_name' => DataClass::EndUserIdentifiableInformation,
    ];

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

    /** @return HasMany<PartyRoleRegistration, $this> */
    public function roleRegistrations(): HasMany
    {
        return $this->hasMany(PartyRoleRegistration::class, 'party_id');
    }
}
