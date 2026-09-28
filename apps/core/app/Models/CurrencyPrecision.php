<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Presisi uang satu mata uang pada satu tenant.
 *
 * Mata uang yang belum punya baris memakai bawaan di `App\Support\Finance\MoneyPrecision`. Master
 * mata uang penuh — kurs, simbol, akun selisih kurs — sengaja belum dibangun (FIN-20).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $currency_code
 * @property int $amount_decimals
 * @property int $unit_amount_decimals
 */
class CurrencyPrecision extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'currency_code', 'amount_decimals', 'unit_amount_decimals'];

    protected function casts(): array
    {
        return ['amount_decimals' => 'integer', 'unit_amount_decimals' => 'integer'];
    }
}
