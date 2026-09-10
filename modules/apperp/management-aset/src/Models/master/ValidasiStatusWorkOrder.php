<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Aturan validasi yang harus dipenuhi sebelum work order boleh berpindah status.
 *
 * `status` di sini adalah status TUJUAN, bukan status saat ini: pemeriksaan yang sama boleh
 * longgar ketika pekerjaan dijadwalkan dan ketat ketika dinyatakan selesai. `keparahan`
 * memisahkan yang sekadar dicatat, yang lewat sebagai peringatan, dan yang menolak transisi.
 *
 * Tabelnya tanpa soft delete, jadi tanpa `deleted_at`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $status
 * @property string $aturan
 * @property bool $aktif
 * @property string $keparahan
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ValidasiStatusWorkOrder extends Model
{
    use HasUlids;
    use MilikTenant;

    /** `informasi` dicatat, `peringatan` lolos dengan jejak, `error` menolak transisi. */
    public const KEPARAHAN = ['informasi', 'peringatan', 'error'];

    protected $table = 'aset_m_validasi_status_work_order';

    protected $fillable = ['tenant_id', 'status', 'aturan', 'aktif', 'keparahan'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }
}
