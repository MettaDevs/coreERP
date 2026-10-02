<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Downtime;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu periode aset tidak dapat dipakai; padanan *Maintenance downtime registration* Dynamics 365 F&O.
 *
 * `selesai` kosong berarti aset masih berhenti. Catatan bersumber `work_order` dibuka saat work
 * order mulai dikerjakan dan ditutup saat selesai; waktunya tetap boleh dikoreksi pengguna.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $aset_id
 * @property Carbon $mulai
 * @property ?Carbon $selesai
 * @property ?string $alasan_downtime_id
 * @property ?string $pemeliharaan_aset_id
 * @property ?string $permintaan_pemeliharaan_id
 * @property string $sumber
 * @property ?string $keterangan
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetDowntime extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    public const MANUAL = 'manual';

    public const WORK_ORDER = 'work_order';

    protected $table = 'aset_tr_downtime_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'aset_id', 'mulai', 'selesai', 'alasan_downtime_id', 'pemeliharaan_aset_id',
        'permintaan_pemeliharaan_id', 'sumber', 'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mulai' => 'datetime',
            'selesai' => 'datetime',
            'version' => 'integer',
        ];
    }
}
