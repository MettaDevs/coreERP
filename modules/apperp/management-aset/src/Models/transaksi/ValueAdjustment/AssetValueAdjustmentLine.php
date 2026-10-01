<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu aset pada satu dokumen penyesuaian nilai, dengan nilai penyesuaiannya (selalu positif; arahnya
 * dibawa jenis header). Nilai buku sebelum dan sesudah kosong selama draf dan dibekukan saat diposting.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $penyesuaian_nilai_aset_id
 * @property int $line_number
 * @property string $aset_id
 * @property string $nilai
 * @property ?string $keterangan
 * @property ?string $nilai_buku_sebelum
 * @property ?string $nilai_buku_sesudah
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetValueAdjustmentLine extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_tr_penyesuaian_nilai_aset_details';

    protected $fillable = [
        'tenant_id', 'penyesuaian_nilai_aset_id', 'line_number', 'aset_id', 'nilai', 'keterangan',
        'nilai_buku_sebelum', 'nilai_buku_sesudah',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'nilai' => 'decimal:2',
            'nilai_buku_sebelum' => 'decimal:2',
            'nilai_buku_sesudah' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<AssetValueAdjustment, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(AssetValueAdjustment::class, 'penyesuaian_nilai_aset_id');
    }
}
