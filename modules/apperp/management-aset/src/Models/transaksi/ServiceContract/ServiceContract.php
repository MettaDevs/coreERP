<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\ServiceContract;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Kontrak servis dengan vendor pemeliharaan: satu vendor, satu periode, banyak aset. Padanan
 * *Maintenance Vendor No.* Business Central yang diperluas menjadi dokumen berperiode.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $nomor_kontrak
 * @property string $vendor_id
 * @property Carbon $berlaku_mulai
 * @property Carbon $berlaku_sampai
 * @property ?string $cakupan
 * @property ?string $nilai_kontrak
 * @property ?string $keterangan
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class ServiceContract extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_tr_kontrak_servis';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'nomor_kontrak', 'vendor_id', 'berlaku_mulai',
        'berlaku_sampai', 'cakupan', 'nilai_kontrak', 'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'nilai_kontrak' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<ServiceContractLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ServiceContractLine::class, 'kontrak_servis_id');
    }
}
