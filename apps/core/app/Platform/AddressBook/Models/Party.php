<?php

namespace App\Platform\AddressBook\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * @property ?string $number
 * @property ?string $creation_key
 * @property string $search_name
 * @property string $status
 * @property int $version
 * @property-read Collection<int, PartyLocation> $locations
 * @property-read Collection<int, PartyRoleRegistration> $roleRegistrations
 */
#[DataClassification(DataClass::CustomerContent)]
class Party extends Model
{
    use HasUlids;
    use SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::EndUserIdentifiableInformation,
        'search_name' => DataClass::EndUserIdentifiableInformation,
        'known_as' => DataClass::EndUserIdentifiableInformation,
        'personal_title' => DataClass::EndUserIdentifiableInformation,
        'personal_suffix' => DataClass::EndUserIdentifiableInformation,
        'initials' => DataClass::EndUserIdentifiableInformation,
        'professional_title' => DataClass::EndUserIdentifiableInformation,
        'professional_suffix' => DataClass::EndUserIdentifiableInformation,
        'first_name' => DataClass::EndUserIdentifiableInformation,
        'middle_name' => DataClass::EndUserIdentifiableInformation,
        'last_name' => DataClass::EndUserIdentifiableInformation,
        'birth_date' => DataClass::EndUserIdentifiableInformation,
        'gender' => DataClass::EndUserIdentifiableInformation,
        'marital_status' => DataClass::EndUserIdentifiableInformation,
        'anniversary_date' => DataClass::EndUserIdentifiableInformation,
        'number_of_children' => DataClass::EndUserIdentifiableInformation,
        'interests' => DataClass::EndUserIdentifiableInformation,
        'memo' => DataClass::EndUserIdentifiableInformation,
    ];

    public const TYPES = ['person', 'organization'];

    protected $fillable = ['tenant_id', 'type', 'name', 'search_name', 'status'];

    /** Field profil yang divalidasi pengelola direktori; tidak dipakai sebagai identitas vendor. */
    public const PROFILE_FIELDS = ['known_as', 'language', 'personal_title', 'personal_suffix', 'first_name', 'middle_name', 'last_name', 'initials', 'professional_title', 'professional_suffix', 'gender', 'marital_status', 'organization_number', 'duns_number', 'abc_code', 'birth_date', 'anniversary_date', 'number_of_children', 'number_of_employees', 'interests', 'memo'];

    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d', 'anniversary_date' => 'date:Y-m-d', 'number_of_children' => 'integer', 'number_of_employees' => 'integer'];
    }

    /** Bentuk nama yang dinormalisasi untuk pencarian dan deteksi kembar. */
    public static function searchName(string $name): string
    {
        return Str::squish(Str::lower($name));
    }

    /** Bentuk nama untuk akun bisnis; legal entity, unit, dan tim tetap berbentuk organisasi. */
    public function identityType(): string
    {
        return $this->type === 'person' ? 'person' : 'organization';
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
