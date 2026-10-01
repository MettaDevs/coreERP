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
 * Satu baris rencana pemeliharaan; padanan *Maintenance plan line* F&O.
 *
 * Baris berbasis waktu memakai `interval` dan `satuan_interval`; baris berbasis counter memakai
 * `jenis_counter_id`, `interval_counter`, dan `toleransi_counter`. Dasar hitungnya ada di
 * `MaintenancePlanBasis`. Baris diperbarui di tempat, bukan diganti, karena usulan jadwal menunjuk
 * id-nya: baris yang berganti id akan melahirkan usulan kembar pada perhitungan berikutnya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rencana_pemeliharaan_id
 * @property int $line_number
 * @property string $dasar
 * @property ?int $interval
 * @property ?string $satuan_interval
 * @property ?string $jenis_counter_id
 * @property ?string $interval_counter
 * @property string $toleransi_counter
 * @property string $maintenance_job_type_id
 * @property ?string $variant_id
 * @property ?string $trade_id
 * @property string $tipe_work_order_id
 * @property ?string $tingkat_layanan_id
 * @property ?int $selesai_dalam_hari
 * @property ?string $deskripsi
 * @property bool $aktif
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class MaintenancePlanLine extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_m_rencana_pemeliharaan_baris';

    protected $fillable = [
        'tenant_id', 'rencana_pemeliharaan_id', 'line_number', 'dasar', 'interval', 'satuan_interval',
        'jenis_counter_id', 'interval_counter', 'toleransi_counter', 'maintenance_job_type_id',
        'variant_id', 'trade_id', 'tipe_work_order_id', 'tingkat_layanan_id', 'selesai_dalam_hari',
        'deskripsi', 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'interval' => 'integer',
            'interval_counter' => 'decimal:2',
            'toleransi_counter' => 'decimal:2',
            'selesai_dalam_hari' => 'integer',
            'aktif' => 'boolean',
            'version' => 'integer',
        ];
    }
}
