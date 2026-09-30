<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alamat pos pada satu tempat ({@see Location}). `formatted` adalah bentuk tercetak saat alamat
 * disimpan; dokumen resmi menyalinnya supaya perubahan alamat kemudian tidak
 * menulis ulang dokumen lama.
 *
 * @property string $formatted
 * @property string $country_region_code
 */
#[DataClassification(DataClass::CustomerContent)]
class PostalAddress extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'province' => DataClass::EndUserIdentifiableInformation,
        'city' => DataClass::EndUserIdentifiableInformation,
        'district' => DataClass::EndUserIdentifiableInformation,
        'street' => DataClass::EndUserIdentifiableInformation,
        'building' => DataClass::EndUserIdentifiableInformation,
        'postbox' => DataClass::EndUserIdentifiableInformation,
        'postal_code' => DataClass::EndUserIdentifiableInformation,
        'formatted' => DataClass::EndUserIdentifiableInformation,
    ];

    protected $fillable = [
        'tenant_id', 'location_id', 'country_region_code', 'province', 'city',
        'district', 'street', 'building', 'postbox', 'postal_code', 'formatted',
    ];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
