<?php

namespace App\Models\transaksi\InventarisasiAset;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AssetBook extends Model
{
    use HasUlids;

    protected $table = 'tr_buku_aset';

    protected $fillable = [
        'tenant_id', 'asset_id', 'depreciation_profile_id', 'book_code',
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
        ];
    }
}
