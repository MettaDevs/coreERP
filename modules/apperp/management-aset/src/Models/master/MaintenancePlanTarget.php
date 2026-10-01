<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Objek rencana pemeliharaan: satu aset, atau seluruh aset satu jenis.
 *
 * Padanan FastTab *Assets* dan *Asset types* pada rencana F&O, dengan satu perbedaan yang disengaja:
 * objek jenis aset dibaca saat jadwal dihitung, jadi aset yang sudah ada ikut, bukan hanya aset yang
 * dibuat sesudah rencana dipasang.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rencana_pemeliharaan_id
 * @property ?string $aset_id
 * @property ?string $jenis_aset_id
 * @property ?Carbon $tanggal_mulai
 * @property bool $aktif
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class MaintenancePlanTarget extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_m_rencana_pemeliharaan_objek';

    protected $fillable = ['tenant_id', 'rencana_pemeliharaan_id', 'aset_id', 'jenis_aset_id', 'tanggal_mulai', 'aktif'];

    protected function casts(): array
    {
        return ['tanggal_mulai' => 'date', 'aktif' => 'boolean', 'version' => 'integer'];
    }
}
