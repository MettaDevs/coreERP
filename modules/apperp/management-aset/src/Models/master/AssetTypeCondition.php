<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DataClassification(DataClass::CustomerContent)]
class AssetTypeCondition extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_m_jenis_aset_kondisi';

    protected $fillable = ['tenant_id', 'jenis_aset_id', 'kondisi_aset_id'];
}
