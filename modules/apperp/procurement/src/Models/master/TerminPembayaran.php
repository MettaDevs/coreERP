<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use Modules\Apperp\Procurement\Models\MasterData;

/**
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property string $kode_termin
 * @property string $nama_termin_bayar
 * @property ?string $keterangan
 * @property bool $aktif
 */
class TerminPembayaran extends MasterData
{
    protected $table = 'procurement_termin_pembayaran';

    protected $fillable = [
        'tenant_id',
        'creation_key',
        'kode_termin',
        'nama_termin_bayar',
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
