<?php

namespace App\Models\transaksi\InventarisasiAset;

use App\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetLocation extends MasterData
{
    protected $table = 'm_lokasi_aset';

    protected $fillable = ['tenant_id', 'creation_key', 'kode', 'parent_id', 'nama', 'keterangan', 'aktif'];

    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
