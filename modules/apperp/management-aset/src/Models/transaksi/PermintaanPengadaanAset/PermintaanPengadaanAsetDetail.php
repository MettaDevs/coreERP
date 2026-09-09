<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris permintaan pengadaan aset.
 *
 * `planning_detail_id` menunjuk baris rencana yang melahirkan permintaan ini dan boleh
 * kosong: permintaan di luar rencana tetap sah. `satuan_id` menunjuk satuan milik Core dan
 * tidak berpasangan dengan kolom kode satuan di tabel ini.
 */
class PermintaanPengadaanAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_permintaan_pengadaan_aset_details';

    protected $fillable = [
        'tenant_id', 'request_id', 'line_number', 'planning_detail_id', 'jenis_aset_id',
        'satuan_id', 'asset_name', 'quantity', 'specification', 'note',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
        ];
    }

    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(PermintaanPengadaanAset::class, 'request_id');
    }
}
