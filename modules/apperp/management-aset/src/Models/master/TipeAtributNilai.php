<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Pilihan nilai untuk atribut bertipe daftar tetap.
 *
 * Tabel tersendiri, bukan JSON di dalam tipe atribut, supaya nilai yang dipilih sebuah aset
 * dapat ditegakkan foreign key. Bukan master penuh: tanpa kode, nama, dan creation_key.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $tipe_atribut_id
 * @property int $urutan
 * @property string $nilai
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class TipeAtributNilai extends Model
{
    use HasUlids;
    use MilikTenant;
    use SoftDeletes;

    protected $table = 'aset_m_tipe_atribut_nilai';

    protected $fillable = ['tenant_id', 'tipe_atribut_id', 'urutan', 'nilai'];

    protected function casts(): array
    {
        return ['urutan' => 'integer'];
    }

    /** @return BelongsTo<TipeAtribut, $this> */
    public function tipeAtribut(): BelongsTo
    {
        return $this->belongsTo(TipeAtribut::class, 'tipe_atribut_id');
    }
}
