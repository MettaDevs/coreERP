<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Satu aset pada satu berita acara serah terima.
 *
 * Kolom `asal_*` kosong selama dokumen masih draf dan diisi saat dokumen diselesaikan.
 * Keadaan asal yang benar adalah keadaan pada saat serah terima terjadi, bukan pada saat
 * dokumen diketik; selama masih draf, layar membacanya langsung dari asetnya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mutasi_aset_id
 * @property int $line_number
 * @property string $aset_id
 * @property ?string $kondisi_aset_id
 * @property ?string $asal_lokasi_id
 * @property ?string $asal_org_unit_id
 * @property ?string $asal_custodian_user_id
 * @property ?string $catatan
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MutasiAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_mutasi_aset_details';

    protected $fillable = [
        'tenant_id', 'mutasi_aset_id', 'line_number', 'aset_id', 'kondisi_aset_id',
        'asal_lokasi_id', 'asal_org_unit_id', 'asal_custodian_user_id', 'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_number' => 'integer'];
    }

    /** @return BelongsTo<MutasiAset, $this> */
    public function mutasi(): BelongsTo
    {
        return $this->belongsTo(MutasiAset::class, 'mutasi_aset_id');
    }

    /** @return BelongsTo<Aset, $this> */
    public function aset(): BelongsTo
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
}
