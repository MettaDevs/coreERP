<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Models\support;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Event Core yang sudah pernah diproses module ini.
 *
 * Bukan data bisnis: satu baris di sini hanya berarti "id event ini sudah pernah sampai".
 * Ia tetap membawa `tenant_id` dan tetap memakai `MilikTenant`, karena keunikannya
 * `(tenant_id, event_id)` — dua tenant boleh punya event dengan id yang sama tanpa saling
 * menutup.
 *
 * **Penyisipannya sengaja tidak lewat instance model.** Dedup memakai `insertOrIgnore`, yang
 * pada PostgreSQL menjadi `ON CONFLICT DO NOTHING`. Alternatifnya — menyimpan lalu menangkap
 * pelanggaran unique — tidak bisa dipakai di sini: pada PostgreSQL sebuah statement yang gagal
 * **membatalkan seluruh transaksi**, dan transaksi itu adalah transaksi keputusan Core. Jadi
 * pengiriman ulang yang sah akan menjatuhkan keputusan yang sah.
 *
 * Konsekuensinya `tenant_id` ditulis eksplisit pada penyisipan itu: `insertOrIgnore` tidak
 * membuat instance, sehingga pengisian otomatis oleh `MilikTenant` tidak berjalan. Yang
 * dijaga trait ini di sini adalah pembacaannya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property Carbon $processed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class ProcessedCoreEvent extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_processed_core_events';

    protected $fillable = ['tenant_id', 'event_id', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
