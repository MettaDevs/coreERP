<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Warranty;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Garansi satu aset; padanan *Vendor warranty* pada aset Dynamics 365 F&O Asset Management.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $aset_id
 * @property ?string $vendor_id
 * @property string $jenis_garansi
 * @property ?string $nomor_referensi
 * @property Carbon $berlaku_mulai
 * @property Carbon $berlaku_sampai
 * @property ?string $catatan
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetWarranty extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    public const FULL = 'penuh';

    public const PARTIAL = 'sebagian';

    /** @var array<string, string> Padanan *full* dan *partial coverage* warranty agreement F&O. */
    public const TYPES = [self::FULL => 'Penuh', self::PARTIAL => 'Sebagian'];

    protected $table = 'aset_tr_garansi_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'aset_id', 'vendor_id', 'jenis_garansi', 'nomor_referensi',
        'berlaku_mulai', 'berlaku_sampai', 'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'version' => 'integer',
        ];
    }
}
