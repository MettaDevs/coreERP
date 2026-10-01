<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\CounterReading;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu pembacaan counter pada satu aset; padanan baris *Asset counters* di F&O.
 *
 * `nilai` adalah angka di meter, `nilai_total` pemakaian kumulatif yang dihitung saat disimpan.
 * Pembacaan tidak pernah diubah: yang salah diarsipkan (hanya yang terakhir) lalu dicatat ulang,
 * karena total setiap pembacaan bergantung pada pembacaan sebelumnya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $aset_id
 * @property string $jenis_counter_id
 * @property Carbon $dibaca_pada
 * @property string $nilai
 * @property string $nilai_total
 * @property bool $reset
 * @property ?string $keterangan
 * @property ?int $created_by_user_id
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class CounterReading extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_tr_pembacaan_counter';

    protected $fillable = [
        'tenant_id', 'creation_key', 'aset_id', 'jenis_counter_id', 'dibaca_pada', 'nilai',
        'nilai_total', 'reset', 'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dibaca_pada' => 'datetime',
            'nilai' => 'decimal:2',
            'nilai_total' => 'decimal:2',
            'reset' => 'boolean',
            'version' => 'integer',
        ];
    }
}
