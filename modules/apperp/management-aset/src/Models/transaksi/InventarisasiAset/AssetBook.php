<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AssetBook extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_buku_aset';

    protected $fillable = [
        'tenant_id', 'asset_id', 'buku_id', 'depreciation_profile_id', 'alternative_profile_id', 'book_code',
        'useful_life_periods', 'convention', 'depreciation_start_on', 'depreciate', 'round_off_depreciation',
        'acquisition_value', 'residual_value', 'accumulated_depreciation',
        'net_book_value', 'status',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_value' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'net_book_value' => 'decimal:2',
            'useful_life_periods' => 'integer',
            'depreciation_start_on' => 'date',
            'depreciate' => 'boolean',
            'round_off_depreciation' => 'decimal:2',
        ];
    }
}
