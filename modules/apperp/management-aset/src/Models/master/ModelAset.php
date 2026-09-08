<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Katalog model barang milik satu pabrikan; padanan "Manufacturers and models" di
 * Dynamics 365 F&O. Pabrikan dan jenis adalah induk yang saling lepas: memilih salah
 * satu tidak menyaring pilihan yang lain.
 */
class ModelAset extends MasterData
{
    protected $table = 'm_model_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'pabrikan_aset_id', 'jenis_aset_id',
        'kode', 'nama', 'keterangan', 'model_number', 'aktif',
    ];

    public function pabrikanAset(): BelongsTo
    {
        return $this->belongsTo(PabrikanAset::class, 'pabrikan_aset_id');
    }

    public function jenisAset(): BelongsTo
    {
        return $this->belongsTo(JenisAset::class, 'jenis_aset_id');
    }
}
