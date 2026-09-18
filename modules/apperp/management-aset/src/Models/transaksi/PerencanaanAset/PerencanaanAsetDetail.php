<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Baris rencana pengadaan aset.
 *
 * `unit` adalah snapshot kode satuan untuk tampilan, sedangkan `satuan_id` menunjuk satuan
 * milik Core dan boleh kosong pada baris lama yang satuannya masih teks warisan.
 *
 * `quantity` dan kedua kolom harga di-cast `decimal:n`, jadi Eloquent memulangkannya sebagai
 * string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $planning_id
 * @property int $line_number
 * @property string $jenis_aset_id
 * @property ?string $satuan_id
 * @property string $nama_aset
 * @property string $unit
 * @property string $quantity
 * @property string $requested_specification
 * @property string $estimated_unit_price
 * @property string $estimated_total_price
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PerencanaanAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_perencanaan_aset_details';

    protected $fillable = [
        'tenant_id', 'planning_id', 'line_number', 'jenis_aset_id', 'satuan_id',
        'nama_aset', 'unit', 'quantity', 'requested_specification',
        'estimated_unit_price', 'estimated_total_price',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
            'estimated_total_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PerencanaanAset, $this> */
    public function perencanaan(): BelongsTo
    {
        return $this->belongsTo(PerencanaanAset::class, 'planning_id');
    }
}
