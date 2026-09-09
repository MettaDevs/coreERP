<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Jembatan jenis pekerjaan maintenance ke jenis aset.
 *
 * Tabelnya tidak punya kolom `id`: primary key-nya gabungan `tenant_id`, `job_type_id`, dan
 * `jenis_aset_id`. Eloquent tidak mengenal primary key gabungan, jadi `$primaryKey` dibuat
 * null — baris dibaca, disisipkan, dan dihapus lewat `where`, bukan lewat `find()`.
 */
class MaintenanceJobTypeAssetType extends Model
{
    use MilikTenant;

    protected $table = 'aset_m_maintenance_job_type_asset_type';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['tenant_id', 'job_type_id', 'jenis_aset_id'];
}
