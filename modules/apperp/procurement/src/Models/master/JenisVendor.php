<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use App\Platform\Modules\Contracts\DataClass;
use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $kode_jenis
 * @property string $nama_jenis_vendor
 * @property ?string $keterangan
 * @property bool $aktif
 */
class JenisVendor extends MasterData
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_jenis_vendor' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_jenis_vendor';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'kode_jenis',
        'nama_jenis_vendor',
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
