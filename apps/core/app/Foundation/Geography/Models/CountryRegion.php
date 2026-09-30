<?php

namespace App\Foundation\Geography\Models;

use App\Foundation\Geography\Models\AddressHierarchy\Province;
use App\Foundation\Geography\Models\AddressHierarchy\TimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Negara. Reference data global, bukan milik tenant, dengan kunci ISO 3166-1 alpha-2.
 *
 * Satu-satunya daftar negara di produk ini. Master wilayah dulu punya daftarnya
 * sendiri di `ref_countries` dengan kolom kode tiga huruf yang diisi campur alpha-2
 * dan alpha-3; keduanya disatukan ke sini pada 29 September 2026 karena satu negara
 * yang hidup dua kali membuat master wilayah tidak pernah menjadi master bagi alamat.
 *
 * @property string $code
 * @property string|null $iso3
 * @property string $name
 * @property string|null $phone_code
 * @property string|null $timezone
 * @property bool $active
 * @property-read Collection<int, Province> $provinces
 * @property-read Collection<int, TimeZone> $timezones
 */
class CountryRegion extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'iso3', 'name', 'phone_code', 'timezone', 'active'];

    protected $casts = ['active' => 'boolean'];

    /** @return HasMany<Province, $this> */
    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'country_code', 'code');
    }

    /** @return HasMany<TimeZone, $this> */
    public function timezones(): HasMany
    {
        return $this->hasMany(TimeZone::class, 'country_code', 'code');
    }
}
