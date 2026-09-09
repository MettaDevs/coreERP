<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Header rencana pengadaan aset satu tahun anggaran.
 *
 * `version` adalah penghitung kunci optimistik yang dinaikkan setiap penyuntingan, bukan
 * nomor revisi dokumen yang dilihat pengguna. `total_estimated_value` adalah jumlah baris
 * yang dihitung ulang saat detail berubah, bukan angka yang diketik.
 */
class PerencanaanAset extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'aset_tr_perencanaan_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'planning_org_unit_id',
        'planned_on', 'planning_year', 'planning_type', 'funding_source', 'responsible_user_id',
        'total_estimated_value', 'status', 'description', 'version',
    ];

    protected function casts(): array
    {
        return [
            'planned_on' => 'date',
            'planning_year' => 'integer',
            'total_estimated_value' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    public function details(): HasMany
    {
        return $this->hasMany(PerencanaanAsetDetail::class, 'planning_id');
    }
}
