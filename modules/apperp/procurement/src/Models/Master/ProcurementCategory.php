<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\Master;

use App\Platform\Modules\Contracts\DataClass;
use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property ?string $creation_payload_hash
 * @property string $kode_pengadaan
 * @property string $kategori_pengadaan
 * @property ?string $keterangan
 * @property bool $aktif
 * @property int $version
 */
class ProcurementCategory extends MasterData
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'kategori_pengadaan' => DataClass::CustomerContent,
        'keterangan' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_kategori_pengadaan';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'creation_payload_hash',
        'kode_pengadaan',
        'kategori_pengadaan',
        'keterangan',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'version' => 'integer',
        ];
    }
}

