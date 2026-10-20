<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $kode_upah
 * @property string $nama_upah
 * @property string $satuan
 * @property ?string $keterangan
 * @property bool $aktif
 */
class ItemJasa extends MasterData
{
    protected $table = 'procurement_item_jasa';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'kode_upah',
        'nama_upah',
        'satuan',
        'keterangan',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }
}
