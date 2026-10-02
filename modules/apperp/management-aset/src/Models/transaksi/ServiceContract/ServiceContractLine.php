<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\ServiceContract;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu aset yang ditanggung kontrak servis.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kontrak_servis_id
 * @property int $line_number
 * @property string $aset_id
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class ServiceContractLine extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_tr_kontrak_servis_aset';

    protected $fillable = ['tenant_id', 'kontrak_servis_id', 'line_number', 'aset_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_number' => 'integer', 'version' => 'integer'];
    }
}
