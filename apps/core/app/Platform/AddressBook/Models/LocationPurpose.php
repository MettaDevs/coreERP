<?php

namespace App\Platform\AddressBook\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kegunaan alamat yang dikenal platform: bisnis, pengiriman, penagihan, pembayaran, rumah. Referensi
 * bersama, bukan milik tenant; isinya dari migration.
 *
 * @property string $code
 * @property string $name
 */
class LocationPurpose extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'name', 'sort_order'];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_values(self::query()->orderBy('sort_order')->pluck('code')->all());
    }
}
