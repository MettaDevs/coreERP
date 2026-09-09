<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nilai satu atribut pada satu aset.
 *
 * Nilainya dipecah menjadi kolom bertipe, bukan satu kolom teks, supaya database menegakkan
 * tipe dan laporan dapat menyaring rentang angka dengan indeks. Hanya satu kolom `nilai_*`
 * yang terisi per baris, mengikuti `data_type` tipe atributnya; untuk daftar tetap yang
 * terisi adalah `tipe_atribut_nilai_id`.
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

    protected function casts(): array
    {
        return [
            'nilai_number' => 'decimal:6',
            'nilai_boolean' => 'boolean',
            'nilai_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
