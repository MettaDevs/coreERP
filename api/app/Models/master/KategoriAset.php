<?php

namespace App\Models\master;

use App\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KategoriAset extends MasterData
{
    protected $table = 'm_kategori_aset';

    protected $fillable = ['tenant_id', 'creation_key', 'group_aset_id', 'kode', 'nama', 'keterangan', 'aktif'];

    public function groupAset(): BelongsTo
    {
        return $this->belongsTo(GroupAset::class, 'group_aset_id');
    }
}
