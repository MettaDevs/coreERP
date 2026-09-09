<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Katalog model barang milik satu pabrikan; padanan "Manufacturers and models" di
 * Dynamics 365 F&O. Pabrikan dan jenis adalah induk yang saling lepas: memilih salah
 * satu tidak menyaring pilihan yang lain.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan
 * pada `MasterData`. Pabrikan wajib karena sebuah model selalu milik satu pabrikan;
 * jenis opsional supaya katalog dapat mulai diisi sebelum klasifikasi teknis ditetapkan.
 *
 * @property string $pabrikan_aset_id
 * @property ?string $jenis_aset_id
 * @property ?string $model_number
 */
class ModelAset extends MasterData
{
    protected $table = 'aset_m_model_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'pabrikan_aset_id', 'jenis_aset_id',
        'kode', 'nama', 'keterangan', 'model_number', 'aktif',
    ];

    /** @return BelongsTo<PabrikanAset, $this> */
    public function pabrikanAset(): BelongsTo
    {
        return $this->belongsTo(PabrikanAset::class, 'pabrikan_aset_id');
    }

    /** @return BelongsTo<JenisAset, $this> */
    public function jenisAset(): BelongsTo
    {
        return $this->belongsTo(JenisAset::class, 'jenis_aset_id');
    }
}
