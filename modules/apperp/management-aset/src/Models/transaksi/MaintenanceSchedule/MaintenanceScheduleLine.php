<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceSchedule;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu usulan jadwal pemeliharaan; padanan *Maintenance schedule line* F&O.
 *
 * Lahir dari perhitungan rencana, bukan dari isian pengguna. Pengguna hanya mengubahnya menjadi work
 * order atau mengabaikannya. Kuncinya — baris rencana, aset, dan jatuh tempo — dijaga indeks unik,
 * sehingga perhitungan yang dijalankan berulang tidak melahirkan usulan kembar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rencana_pemeliharaan_id
 * @property string $rencana_baris_id
 * @property string $aset_id
 * @property string $legal_entity_id
 * @property ?string $responsible_org_unit_id
 * @property Carbon $jatuh_tempo
 * @property ?string $nilai_jatuh_tempo
 * @property ?string $nilai_counter
 * @property string $status
 * @property ?string $pemeliharaan_aset_id
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class MaintenanceScheduleLine extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_tr_jadwal_pemeliharaan';

    protected $fillable = [
        'tenant_id', 'rencana_pemeliharaan_id', 'rencana_baris_id', 'aset_id', 'legal_entity_id',
        'responsible_org_unit_id', 'jatuh_tempo', 'nilai_jatuh_tempo', 'nilai_counter', 'status',
        'pemeliharaan_aset_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'jatuh_tempo' => 'date',
            'nilai_jatuh_tempo' => 'decimal:2',
            'nilai_counter' => 'decimal:2',
            'version' => 'integer',
        ];
    }
}
