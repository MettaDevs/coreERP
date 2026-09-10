<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Nilai satu atribut pada satu aset.
 *
 * Nilainya dipecah menjadi kolom bertipe, bukan satu kolom teks, supaya database menegakkan
 * tipe dan laporan dapat menyaring rentang angka dengan indeks. Hanya satu kolom `nilai_*`
 * yang terisi per baris, mengikuti `data_type` tipe atributnya; untuk daftar tetap yang
 * terisi adalah `tipe_atribut_nilai_id`.
 *
 * `nilai_number` di-cast `decimal:6`, jadi Eloquent memulangkannya sebagai string, bukan
 * float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $asset_id
 * @property string $tipe_atribut_id
 * @property ?string $nilai_text
 * @property ?string $nilai_number
 * @property ?bool $nilai_boolean
 * @property ?Carbon $nilai_date
 * @property ?string $tipe_atribut_nilai_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AssetAttribute extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_aset_atribut';

    protected $fillable = [
        'tenant_id', 'asset_id', 'tipe_atribut_id',
        'nilai_text', 'nilai_number', 'nilai_boolean', 'nilai_date', 'tipe_atribut_nilai_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nilai_number' => 'decimal:6',
            'nilai_boolean' => 'boolean',
            'nilai_date' => 'date',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
