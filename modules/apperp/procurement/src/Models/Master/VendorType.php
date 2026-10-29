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
 * @property string $kode_jenis
 * @property string $nama_jenis_vendor
 * @property ?string $keterangan
 * @property bool $aktif
 * @property int $version
 */
class VendorType extends MasterData
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_jenis_vendor' => DataClass::CustomerContent,
        'keterangan' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_jenis_vendor';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'creation_payload_hash',
        'kode_jenis',
        'nama_jenis_vendor',
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
