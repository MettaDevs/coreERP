<?php

namespace App\Models\master;

use App\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntitasAset extends MasterData
{
    protected $table = 'm_entitas_aset';

    protected $fillable = ['tenant_id', 'creation_key', 'jenis_aset_id', 'kode', 'nama', 'keterangan', 'aktif'];

    public function jenisAset(): BelongsTo
    {
        return $this->belongsTo(JenisAset::class, 'jenis_aset_id');
    }
}
