<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header permintaan pengadaan aset.
 *
 * `workflow_instance_id` menunjuk instance workflow milik Core dan unik per tenant, jadi satu
 * permintaan tidak dapat diikat ke dua persetujuan sekaligus. `version` adalah penghitung
 * kunci optimistik, bukan nomor revisi yang dilihat pengguna.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $requesting_org_unit_id
 * @property string $requester_user_id
 * @property Carbon $requested_on
 * @property string $status
 * @property ?string $workflow_instance_id
 * @property ?string $description
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PermintaanPengadaanAset extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'aset_tr_permintaan_pengadaan_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'requesting_org_unit_id',
        'requester_user_id', 'requested_on', 'status', 'workflow_instance_id',
        'description', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_on' => 'date',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<PermintaanPengadaanAsetDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(PermintaanPengadaanAsetDetail::class, 'request_id');
    }
}
