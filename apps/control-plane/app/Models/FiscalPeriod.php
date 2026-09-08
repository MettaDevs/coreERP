<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Kolom tanggal di-cast menjadi `Carbon`; anotasi ini yang memberitahukannya kepada
 * analisa statis. Tanpa itu, kode yang memanggil `toDateString()` — cara yang benar —
 * dilaporkan sebagai kesalahan, dan yang tergoda dilakukan orang adalah menghindari
 * pemanggilannya, bukan melengkapi tipenya.
 *
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
class FiscalPeriod extends Model
{
    use HasUlids;

    protected $fillable = ['fiscal_year_id', 'ordinal', 'name', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function year(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
