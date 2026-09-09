<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;

/**
 * Dokumen siklus hidup aset: mutasi, pemusnahan, penjualan, dan sejenisnya dalam satu tabel,
 * dibedakan oleh `jenis_dokumen`.
 *
 * Tabelnya tidak memakai soft delete — dokumen siklus dibatalkan lewat `status`, tidak pernah
 * dihapus. `asset_id` boleh kosong karena dokumen dapat dibuat sebelum asetnya tercatat.
 */
class DokumenSiklusAset extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_dokumen_siklus_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'jenis_dokumen', 'kode', 'legal_entity_id',
        'responsible_org_unit_id', 'asset_id', 'tanggal', 'status', 'workflow_instance_id',
        'nilai', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nilai' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
