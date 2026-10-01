<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceRequest;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Permintaan pemeliharaan; padanan *Maintenance request* di Dynamics 365 F&O Asset Management.
 *
 * Laporan kerusakan atau kebutuhan perbaikan dari sebuah unit atas aset atau lokasi. Ia bukan
 * perintah kerja: perencana menerima atau menolaknya, dan yang diterima dibuatkan satu work order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $responsible_org_unit_id
 * @property string $jenis_permintaan_id
 * @property ?string $aset_id
 * @property ?string $lokasi_aset_id
 * @property string $deskripsi
 * @property ?string $tingkat_layanan_id
 * @property ?string $sebab_kerusakan_id
 * @property string $status
 * @property ?Carbon $diajukan_pada
 * @property ?Carbon $diputuskan_pada
 * @property ?string $diputuskan_oleh_user_id
 * @property ?string $alasan_penolakan
 * @property ?string $pemeliharaan_aset_id
 * @property ?int $created_by_user_id
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class MaintenanceRequest extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'diputuskan_oleh_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    protected $table = 'aset_tr_permintaan_pemeliharaan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id',
        'jenis_permintaan_id', 'aset_id', 'lokasi_aset_id', 'deskripsi', 'tingkat_layanan_id',
        'sebab_kerusakan_id', 'status', 'diajukan_pada', 'diputuskan_pada', 'diputuskan_oleh_user_id',
        'alasan_penolakan', 'pemeliharaan_aset_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diajukan_pada' => 'datetime',
            'diputuskan_pada' => 'datetime',
            'version' => 'integer',
        ];
    }
}
