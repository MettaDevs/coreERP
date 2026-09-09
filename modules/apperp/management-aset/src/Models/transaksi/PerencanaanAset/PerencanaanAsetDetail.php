<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris rencana pengadaan aset.
 *
 * `unit` adalah snapshot kode satuan untuk tampilan, sedangkan `satuan_id` menunjuk satuan
 * milik Core dan boleh kosong pada baris lama yang satuannya masih teks warisan.
 */
class PerencanaanAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_perencanaan_aset_details';

    protected $fillable = [
        'tenant_id', 'planning_id', 'line_number', 'jenis_aset_id', 'satuan_id',
        'asset_name', 'unit', 'quantity', 'requested_specification',
        'estimated_unit_price', 'estimated_total_price',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
            'estimated_total_price' => 'decimal:2',
        ];
    }

    public function perencanaan(): BelongsTo
    {
        return $this->belongsTo(PerencanaanAset::class, 'planning_id');
    }
}
