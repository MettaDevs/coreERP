<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Dokumen siklus hidup aset: mutasi, pemusnahan, penjualan, dan sejenisnya dalam satu tabel,
 * dibedakan oleh `jenis_dokumen`.
 *
 * Tabelnya tidak memakai soft delete — dokumen siklus dibatalkan lewat `status`, tidak pernah
 * dihapus. `aset_id` boleh kosong karena dokumen dapat dibuat sebelum asetnya tercatat.
 *
 * `nilai` di-cast `decimal:2`, jadi Eloquent memulangkannya sebagai string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $jenis_dokumen
 * @property string $kode
 * @property string $legal_entity_id
 * @property ?string $responsible_org_unit_id
 * @property ?string $aset_id
 * @property Carbon $tanggal
 * @property string $status
 * @property ?string $workflow_instance_id
 * @property ?string $nilai
 * @property ?string $keterangan
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class DokumenSiklusAset extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_dokumen_siklus_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'jenis_dokumen', 'kode', 'legal_entity_id',
        'responsible_org_unit_id', 'aset_id', 'tanggal', 'status', 'workflow_instance_id',
        'nilai', 'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nilai' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Aset, $this> */
    public function aset(): BelongsTo
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
}
