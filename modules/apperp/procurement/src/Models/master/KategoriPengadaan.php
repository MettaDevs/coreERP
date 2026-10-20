<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $kode_pengadaan
 * @property string $kategori_pengadaan
 * @property ?string $keterangan
 * @property bool $aktif
 */
class KategoriPengadaan extends MasterData
{
    protected $table = 'procurement_kategori_pengadaan';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'kode_pengadaan',
        'kategori_pengadaan',
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
