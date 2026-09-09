<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penempelan tipe atribut pada jenis aset; aset mewarisi atribut dari jenisnya.
 *
 * Bukan master penuh: tanpa kode dan tanpa nama. `wajib` di sini berarti aset dari jenis
 * tersebut harus mengisi atribut itu, bukan bahwa penempelannya sendiri wajib ada.
 */
class JenisAsetAtribut extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'aset_m_jenis_aset_atribut';

    protected $fillable = ['tenant_id', 'jenis_aset_id', 'tipe_atribut_id', 'wajib', 'urutan'];

    protected function casts(): array
    {
        return [
            'wajib' => 'boolean',
            'urutan' => 'integer',
        ];
    }

    public function jenisAset(): BelongsTo
    {
        return $this->belongsTo(JenisAset::class, 'jenis_aset_id');
    }

    public function tipeAtribut(): BelongsTo
    {
        return $this->belongsTo(TipeAtribut::class, 'tipe_atribut_id');
    }
}
