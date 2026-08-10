<?php

namespace App\Models\transaksi\InventarisasiAset;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'tr_penerimaan_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id',
        'group_aset_id', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id',
        'parent_asset_id', 'asset_location_id', 'financial_dimension_org_unit_id',
        'serial_number', 'model_number', 'acquired_on', 'placed_in_service_on',
        'acquisition_value', 'currency_code', 'lifecycle_state', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'placed_in_service_on' => 'date',
            'acquisition_value' => 'decimal:2',
        ];
    }
}
