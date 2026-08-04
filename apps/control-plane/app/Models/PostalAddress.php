<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alamat pos pada satu lokasi. `formatted` adalah bentuk tercetak saat alamat
 * disimpan; dokumen resmi menyalinnya supaya perubahan alamat kemudian tidak
 * menulis ulang dokumen lama.
 */
class PostalAddress extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'location_id', 'country_region_code', 'province', 'city',
        'district', 'street', 'building', 'postbox', 'postal_code', 'formatted',
    ];

    /** @return BelongsTo<PartyLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(PartyLocation::class, 'location_id');
    }
}
