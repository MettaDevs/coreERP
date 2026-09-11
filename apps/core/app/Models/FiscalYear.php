<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
class FiscalYear extends Model
{
    use HasUlids;

    protected $fillable = ['fiscal_calendar_id', 'name', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<FiscalCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(FiscalCalendar::class, 'fiscal_calendar_id');
    }

    /** @return HasMany<FiscalPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }
}
