<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Buku penyusutan satu aset: satu baris per pasangan aset dan buku.
 *
 * Kolom uangnya di-cast `decimal:2`, jadi Eloquent memulangkannya sebagai string, bukan
 * float. `closed_on` sengaja tidak ikut `casts()` — ia hanya ditulis lewat query update,
 * tidak pernah dibaca sebagai properti — sehingga tipenya tetap string apa adanya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $aset_id
 * @property ?string $buku_id
 * @property ?string $depreciation_profile_id
 * @property ?string $alternative_profile_id
 * @property string $book_code
 * @property ?int $useful_life_periods
 * @property ?string $convention
 * @property ?Carbon $depreciation_start_on
 * @property bool $depreciate
 * @property string $round_off_depreciation
 * @property string $acquisition_value
 * @property string $residual_value
 * @property string $accumulated_depreciation
 * @property string $opening_accumulated_depreciation
 * @property int $elapsed_periods_offset
 * @property string $net_book_value
 * @property string $status
 * @property ?string $closed_on
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class BukuAset extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_buku_aset';

    protected $fillable = [
        'tenant_id', 'aset_id', 'buku_id', 'depreciation_profile_id', 'alternative_profile_id', 'book_code',
        'useful_life_periods', 'convention', 'depreciation_start_on', 'depreciate', 'round_off_depreciation',
        'acquisition_value', 'residual_value', 'accumulated_depreciation',
        'opening_accumulated_depreciation', 'elapsed_periods_offset', 'net_book_value', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'acquisition_value' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'opening_accumulated_depreciation' => 'decimal:2',
            'elapsed_periods_offset' => 'integer',
            'net_book_value' => 'decimal:2',
            'useful_life_periods' => 'integer',
            'depreciation_start_on' => 'date',
            'depreciate' => 'boolean',
            'round_off_depreciation' => 'decimal:2',
        ];
    }
}
