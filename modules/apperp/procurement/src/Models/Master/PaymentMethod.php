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
 * @property string $kode_bayar
 * @property string $nama_metode_bayar
 * @property ?string $keterangan
 * @property bool $aktif
 * @property int $version
 */
class PaymentMethod extends MasterData
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_metode_bayar' => DataClass::CustomerContent,
        'keterangan' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_metode_pembayaran';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'creation_payload_hash',
        'kode_bayar',
        'nama_metode_bayar',
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
