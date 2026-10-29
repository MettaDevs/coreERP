<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\Master;

use App\Platform\Modules\Contracts\DataClass;
use Illuminate\Support\Carbon;
use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property ?string $creation_payload_hash
 * @property string $group_item
 * @property string $jenis_item
 * @property string $kode_item
 * @property string $nama_item
 * @property string $satuan
 * @property string $harga_perkiraan
 * @property Carbon|null $tanggal_update
 * @property ?string $keterangan
 * @property int $version
 */
class OwnerEstimate extends MasterData
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_item' => DataClass::CustomerContent,
        'keterangan' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_harga_perkiraan';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'creation_payload_hash',
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
            'harga_perkiraan' => 'decimal:2',
            'tanggal_update' => 'date:Y-m-d',
            'version' => 'integer',
        ];
    }
}
