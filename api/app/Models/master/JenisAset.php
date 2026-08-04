<?php

namespace App\Models\master;

use App\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JenisAset extends MasterData
{
    protected $table = 'm_jenis_aset';

    protected $fillable = ['tenant_id', 'creation_key', 'kategori_aset_id', 'kode', 'nama', 'keterangan', 'aktif'];

    public function kategoriAset(): BelongsTo
    {
        return $this->belongsTo(KategoriAset::class, 'kategori_aset_id');
    }
}
