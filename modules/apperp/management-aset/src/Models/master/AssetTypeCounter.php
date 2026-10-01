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
 * Counter yang boleh dibaca pada aset satu jenis; padanan FastTab *Asset types* pada counter F&O.
 *
 * Counter yang tidak punya baris di sini berlaku untuk semua jenis aset, sama seperti jenis
 * pekerjaan maintenance yang belum dikaitkan ke jenis aset mana pun.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $jenis_aset_id
 * @property string $jenis_counter_id
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetTypeCounter extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_m_jenis_aset_counter';

    protected $fillable = ['tenant_id', 'jenis_aset_id', 'jenis_counter_id'];
}
