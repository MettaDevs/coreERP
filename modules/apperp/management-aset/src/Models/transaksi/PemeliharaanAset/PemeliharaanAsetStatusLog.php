<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jejak perpindahan status work order.
 *
 * Baris jejak tidak pernah disunting, jadi tabelnya hanya punya `created_at`; `UPDATED_AT`
 * dimatikan supaya Eloquent tidak menulis kolom yang tidak ada. `peringatan` menyimpan
 * validasi berlevel peringatan yang dilewati, sehingga "boleh lanjut dengan peringatan" tetap
 * dapat dibedakan dari "semuanya lengkap" ketika riwayat dibaca berbulan-bulan kemudian.
 *
 * Tidak ada `updated_at` di sini; `created_at` tidak nullable karena kolomnya diisi database
 * lewat `useCurrent()`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pemeliharaan_aset_id
 * @property string $dari_status
 * @property string $ke_status
 * @property ?string $oleh_user_id
 * @property ?string $alasan
 * @property ?string $peringatan
 * @property Carbon $created_at
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<PemeliharaanAset, $this> */
    public function pemeliharaan(): BelongsTo
    {
        return $this->belongsTo(PemeliharaanAset::class, 'pemeliharaan_aset_id');
    }
}
