<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Reclassification;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu aset asal pada dokumen reklasifikasi. Pindah group menyebut group tujuan; pecah menyebut persentase
 * atau nilai perolehan yang dipindah, nama aset baru, dan boleh menyebut group tujuan. Group asal, aset baru,
 * dan nilai perolehan yang dipindah kosong selama draf dan dibekukan saat diposting.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reklasifikasi_aset_id
 * @property int $line_number
 * @property string $aset_id
 * @property ?string $group_aset_tujuan_id
 * @property ?string $persen
 * @property ?string $nilai_perolehan
 * @property ?string $nama_aset_baru
 * @property ?string $keterangan
 * @property ?string $group_aset_asal_id
 * @property ?string $aset_baru_id
 * @property ?string $nilai_perolehan_dipindah
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetReclassificationLine extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    /**
     * Nama aset baru adalah nama barang, bukan nama orang, sama seperti `nama` di register aset.
     *
     * @var array<string, DataClass>
     */
    public const COLUMN_CLASSIFICATION = [
        'nama_aset_baru' => DataClass::CustomerContent,
    ];

    protected $table = 'aset_tr_reklasifikasi_aset_details';

    protected $fillable = [
        'tenant_id', 'reklasifikasi_aset_id', 'line_number', 'aset_id', 'group_aset_tujuan_id', 'persen',
        'nilai_perolehan', 'nama_aset_baru', 'keterangan', 'group_aset_asal_id', 'aset_baru_id', 'nilai_perolehan_dipindah',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'persen' => 'decimal:6',
            'nilai_perolehan' => 'decimal:2',
            'nilai_perolehan_dipindah' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<AssetReclassification, $this> */
    public function reclassification(): BelongsTo
    {
        return $this->belongsTo(AssetReclassification::class, 'reklasifikasi_aset_id');
    }
}
