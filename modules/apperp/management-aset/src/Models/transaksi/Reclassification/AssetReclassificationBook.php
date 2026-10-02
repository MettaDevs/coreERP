<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Reclassification;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Yang dipindah satu baris reklasifikasi pada satu buku penyusutan, ditulis sekali saat diposting: keluar dari
 * buku aset asal, masuk ke buku aset tujuan. Padanan pasangan FA Ledger Entry ber-*Reclassification Entry* di
 * Business Central; laporan nilai buku dan rekonsiliasi membaca mutasi reklasifikasi dari sini.
 *
 * Pada pindah group, buku aset asal dan tujuannya sama; yang berubah hanya group-nya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reklasifikasi_aset_id
 * @property string $reklasifikasi_aset_detail_id
 * @property Carbon $tanggal
 * @property string $jenis
 * @property string $buku_id
 * @property string $aset_asal_id
 * @property string $buku_aset_asal_id
 * @property string $group_aset_asal_id
 * @property string $aset_tujuan_id
 * @property string $buku_aset_tujuan_id
 * @property string $group_aset_tujuan_id
 * @property string $nilai_perolehan
 * @property string $akumulasi_penyusutan
 * @property string $penurunan_nilai
 * @property string $kenaikan_nilai
 * @property string $nilai_sisa
 * @property bool $dijurnal
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetReclassificationBook extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'aset_tr_reklasifikasi_aset_buku';

    protected $fillable = [
        'tenant_id', 'reklasifikasi_aset_id', 'reklasifikasi_aset_detail_id', 'tanggal', 'jenis', 'buku_id',
        'aset_asal_id', 'buku_aset_asal_id', 'group_aset_asal_id', 'aset_tujuan_id', 'buku_aset_tujuan_id',
        'group_aset_tujuan_id', 'nilai_perolehan', 'akumulasi_penyusutan', 'penurunan_nilai', 'kenaikan_nilai',
        'nilai_sisa', 'dijurnal',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nilai_perolehan' => 'decimal:2',
            'akumulasi_penyusutan' => 'decimal:2',
            'penurunan_nilai' => 'decimal:2',
            'kenaikan_nilai' => 'decimal:2',
            'nilai_sisa' => 'decimal:2',
            'dijurnal' => 'boolean',
        ];
    }
}
