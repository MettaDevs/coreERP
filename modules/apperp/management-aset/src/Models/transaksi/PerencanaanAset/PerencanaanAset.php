<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header rencana pengadaan aset satu tahun anggaran.
 *
 * `version` adalah penghitung kunci optimistik yang dinaikkan setiap penyuntingan, bukan
 * nomor revisi dokumen yang dilihat pengguna. `total_estimated_value` adalah jumlah baris
 * yang dihitung ulang saat detail berubah, bukan angka yang diketik; ia di-cast `decimal:2`,
 * jadi Eloquent memulangkannya sebagai string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $planning_org_unit_id
 * @property Carbon $planned_on
 * @property int $planning_year
 * @property string $planning_type
 * @property ?string $funding_source
 * @property ?string $responsible_user_id
 * @property string $total_estimated_value
 * @property string $status
 * @property ?string $description
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'planned_on' => 'date',
            'planning_year' => 'integer',
            'total_estimated_value' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<PerencanaanAsetDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(PerencanaanAsetDetail::class, 'planning_id');
    }
}
