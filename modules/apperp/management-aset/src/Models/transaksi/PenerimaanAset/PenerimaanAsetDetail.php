<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris penerimaan: satu jenis barang, sebanyak yang datang.
 *
 * `jumlah` bukan kuantitas yang ikut hidup bersama aset. Ia hanya menyatakan berapa baris
 * register yang dilahirkan baris ini saat dokumen diselesaikan; sesudah itu setiap aset
 * berdiri sendiri dengan kodenya masing-masing.
 *
 * `nilai_per_unit` disimpan per unit, bukan total, karena ambang kapitalisasi dibandingkan
 * terhadap nilai satu aset. Dua puluh kursi lima ratus ribu tidak menjadi satu aset
 * sepuluh juta hanya karena dibeli bersamaan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $penerimaan_aset_id
 * @property int $line_number
 * @property string $nama
 * @property string $group_aset_id
 * @property string $jenis_aset_id
 * @property ?string $kondisi_aset_id
 * @property ?string $pabrikan_aset_id
 * @property ?string $model_aset_id
 * @property ?string $model_number
 * @property int $jumlah
 * @property string $nilai_per_unit
 * @property string $residu_per_unit
 * @property ?string $permintaan_pembelian_detail_id
 * @property ?array<int, array<string, mixed>> $atribut
 * @property ?string $keterangan
 */
class PenerimaanAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_penerimaan_aset_details';

    protected $fillable = [
        'tenant_id', 'penerimaan_aset_id', 'line_number', 'nama', 'group_aset_id',
        'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id',
        'model_number', 'jumlah', 'nilai_per_unit', 'ppn_per_unit', 'residu_per_unit',
        'permintaan_pembelian_detail_id', 'atribut', 'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'jumlah' => 'integer',
            // Harga satuan boleh lebih halus dari nilai jurnal (K-20); kolomnya decimal(24,6).
            'nilai_per_unit' => 'decimal:6',
            'ppn_per_unit' => 'decimal:6',
            'residu_per_unit' => 'decimal:2',
            'atribut' => 'array',
        ];
    }

    /** @return BelongsTo<PenerimaanAset, $this> */
    public function penerimaan(): BelongsTo
    {
        return $this->belongsTo(PenerimaanAset::class, 'penerimaan_aset_id');
    }
}
