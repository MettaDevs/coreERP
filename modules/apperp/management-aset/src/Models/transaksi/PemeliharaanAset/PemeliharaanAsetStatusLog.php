<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak perpindahan status work order.
 *
 * Baris jejak tidak pernah disunting, jadi tabelnya hanya punya `created_at`; `UPDATED_AT`
 * dimatikan supaya Eloquent tidak menulis kolom yang tidak ada. `peringatan` menyimpan
 * validasi berlevel peringatan yang dilewati, sehingga "boleh lanjut dengan peringatan" tetap
 * dapat dibedakan dari "semuanya lengkap" ketika riwayat dibaca berbulan-bulan kemudian.
 */
class PemeliharaanAsetStatusLog extends Model
{
    use HasUlids;
    use MilikTenant;

    public const UPDATED_AT = null;

    protected $table = 'aset_tr_pemeliharaan_aset_status_log';

    protected $fillable = [
        'tenant_id', 'pemeliharaan_aset_id', 'dari_status', 'ke_status',
        'oleh_user_id', 'alasan', 'peringatan',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function pemeliharaan(): BelongsTo
    {
        return $this->belongsTo(PemeliharaanAset::class, 'pemeliharaan_aset_id');
    }
}
