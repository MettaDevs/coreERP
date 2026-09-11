<?php

namespace Modules\Apperp\HumanResources\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Posisi: satu kursi pada satu unit kerja, berlaku sepanjang periode tertentu.
 *
 * `operating_unit_id` adalah satu-satunya kolom module ini yang dibaca lingkup kebijakan data
 * `human-resources.workforce-responsibility`. Penugasan pun disaring lewat kolom ini, bukan
 * lewat kolom miliknya sendiri — yang menentukan siapa boleh melihat sebuah penugasan adalah
 * unit kerja posisinya, bukan unit kerja pekerjanya.
 *
 * `valid_from` dan `valid_until` sengaja tidak di-cast menjadi `Carbon`. Keduanya kolom
 * `date`, dan tanpa cast Eloquent memulangkannya sebagai `'2026-01-01'` — persis bentuk yang
 * dipulangkan endpoint ini sejak awal, baik pada daftar maupun pada jawaban pembuatan. Cast
 * `date` akan mengubahnya menjadi `'2026-01-01T00:00:00.000000Z'`, yaitu perubahan bentuk
 * jawaban yang tidak diminta siapa pun. Tidak ada kode module yang butuh objek tanggal di
 * sini: pembandingan periode dikerjakan database atas nilai permintaan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $code
 * @property string $name
 * @property string $job_id
 * @property string $operating_unit_id
 * @property string $valid_from
 * @property ?string $valid_until
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Position extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'hr_positions';

    protected $fillable = ['tenant_id', 'creation_key', 'code', 'name', 'job_id', 'operating_unit_id', 'valid_from', 'valid_until'];

    /**
     * Alasannya sama dengan pada `Worker`: bentuk jawaban endpoint tidak boleh ikut berubah
     * hanya karena tabelnya mendapat kolom soft delete.
     *
     * @var list<string>
     */
    protected $hidden = ['deleted_at'];

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}
