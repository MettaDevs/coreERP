<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Pohon lokasi fisik aset; padanan "Functional location" di Dynamics 365 F&O.
 *
 * Hierarkinya hidup di data, bukan di skema: `parent_id` menunjuk dirinya sendiri
 * sedalam yang dibutuhkan tenant. Ia sengaja terpisah dari struktur organisasi Core,
 * karena "di mana benda ini berada" dan "siapa yang bertanggung jawab" adalah dua
 * pertanyaan berbeda yang berubah karena sebab berbeda. `org_unit_id` adalah
 * jembatan opsional antara keduanya.
 */
class LokasiAset extends MasterData
{
    protected $table = 'm_lokasi_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'parent_id', 'tipe_lokasi_id', 'org_unit_id',
    ];

    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function tipeLokasi(): BelongsTo
    {
        return $this->belongsTo(TipeLokasiAset::class, 'tipe_lokasi_id');
    }
}
