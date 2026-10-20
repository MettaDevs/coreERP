<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $group_item
 * @property string $jenis_item
 * @property string $kode_item
 * @property string $nama_item
 * @property string $satuan
 * @property float $harga_perkiraan
 * @property ?string $tanggal_update
 * @property ?string $keterangan
 */
class HargaPerkiraan extends MasterData
{
    protected $table = 'procurement_harga_perkiraan';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'group_item',
        'jenis_item',
        'kode_item',
        'nama_item',
        'satuan',
        'harga_perkiraan',
        'tanggal_update',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'harga_perkiraan' => 'float',
            'tanggal_update' => 'date:Y-m-d',
        ];
    }
}
