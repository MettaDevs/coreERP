<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Header permintaan pengadaan aset.
 *
 * `workflow_instance_id` menunjuk instance workflow milik Core dan unik per tenant, jadi satu
 * permintaan tidak dapat diikat ke dua persetujuan sekaligus. `version` adalah penghitung
 * kunci optimistik, bukan nomor revisi yang dilihat pengguna.
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

    protected function casts(): array
    {
        return [
            'requested_on' => 'date',
            'version' => 'integer',
        ];
    }

    public function details(): HasMany
    {
        return $this->hasMany(PermintaanPengadaanAsetDetail::class, 'request_id');
    }
}
