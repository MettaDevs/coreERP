<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Baris permintaan pengadaan aset.
 *
 * `planning_detail_id` menunjuk baris rencana yang melahirkan permintaan ini dan boleh
 * kosong: permintaan di luar rencana tetap sah. `satuan_id` menunjuk satuan milik Core dan
 * tidak berpasangan dengan kolom kode satuan di tabel ini.
 *
 * `quantity` di-cast `decimal:4`, jadi Eloquent memulangkannya sebagai string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $request_id
 * @property int $line_number
 * @property ?string $planning_detail_id
 * @property string $jenis_aset_id
 * @property string $satuan_id
 * @property string $nama_aset
 * @property string $quantity
 * @property string $specification
 * @property ?string $note
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PermintaanPengadaanAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_permintaan_pengadaan_aset_details';

    protected $fillable = [
        'tenant_id', 'request_id', 'line_number', 'planning_detail_id', 'jenis_aset_id',
        'satuan_id', 'nama_aset', 'quantity', 'specification', 'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PermintaanPengadaanAset, $this> */
    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(PermintaanPengadaanAset::class, 'request_id');
    }
}
