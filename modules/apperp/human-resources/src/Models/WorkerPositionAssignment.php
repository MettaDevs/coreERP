<?php

namespace Modules\Apperp\HumanResources\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Penugasan seorang pekerja pada satu posisi, berlaku sepanjang periode tertentu.
 *
 * Satu posisi hanya boleh terisi satu pekerja pada satu waktu; yang menjaganya adalah
 * pemeriksaan tumpang tindih periode di controller, bukan indeks unik — periode yang
 * bertumpuk tidak dapat dinyatakan sebagai kunci unik biasa.
 *
 * `valid_from` dan `valid_until` tidak di-cast, dengan alasan yang sama seperti pada
 * `Position`: bentuk jawaban endpoint tetap `'2026-01-01'`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $worker_id
 * @property string $position_id
 * @property string $valid_from
 * @property ?string $valid_until
 * @property bool $is_primary
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WorkerPositionAssignment extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'hr_worker_position_assignments';

    protected $fillable = ['tenant_id', 'worker_id', 'position_id', 'valid_from', 'valid_until', 'is_primary'];

    /**
     * Alasannya sama dengan pada `Worker`: bentuk jawaban endpoint tidak boleh ikut berubah
     * hanya karena tabelnya mendapat kolom soft delete.
     *
     * @var list<string>
     */
    protected $hidden = ['deleted_at'];

    /**
     * `is_primary` di-cast, berbeda dari kolom tanggal di atas.
     *
     * Jawaban pembuatan sudah memulangkannya sebagai boolean sejati — nilainya datang dari
     * validasi permintaan — sedangkan daftar memulangkan apa pun yang dipulangkan driver.
     * Cast ini membuat keduanya sama, dan tidak ada bentuk lain yang pernah dijanjikan.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /** @return BelongsTo<Worker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }
}
