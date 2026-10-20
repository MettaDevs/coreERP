<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use App\Platform\Modules\Contracts\DataClass;
use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $kode_bayar
 * @property string $nama_metode_bayar
 * @property ?string $keterangan
 * @property bool $aktif
 */
class MetodePembayaran extends MasterData
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_metode_bayar' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_metode_pembayaran';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'kode_bayar',
        'nama_metode_bayar',
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
