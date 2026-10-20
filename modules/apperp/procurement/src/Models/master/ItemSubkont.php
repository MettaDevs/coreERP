<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $kode_subkont
 * @property string $nama_subkont
 * @property string $satuan
 * @property ?string $keterangan
 * @property bool $aktif
 */
class ItemSubkont extends MasterData
{
    protected $table = 'procurement_item_subkont';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'kode_subkont',
        'nama_subkont',
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
