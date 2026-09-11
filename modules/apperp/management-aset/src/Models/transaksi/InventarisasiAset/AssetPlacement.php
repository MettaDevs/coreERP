<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Riwayat penempatan aset; satu baris per perubahan, bukan satu baris per aset.
 *
 * Penempatan yang berlaku pada suatu tanggal adalah baris dengan `effective_on` terbesar yang
 * tidak melewati tanggal itu. `received_by_user_id` dan `custodian_user_id` menyimpan id
 * pengguna Core yang bersifat opaque, jadi bertipe string, bukan ULID.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $asset_id
 * @property ?string $receiving_org_unit_id
 * @property ?string $usage_org_unit_id
 * @property ?string $received_by_user_id
 * @property ?string $custodian_user_id
 * @property ?string $asset_location_id
 * @property Carbon $effective_on
 * @property ?string $reason
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AssetPlacement extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_penempatan_aset';

    protected $fillable = [
        'tenant_id', 'asset_id', 'receiving_org_unit_id', 'usage_org_unit_id',
        'received_by_user_id', 'custodian_user_id', 'asset_location_id', 'effective_on', 'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
