<?php

namespace Modules\Apperp\HumanResources\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Pekerja yang tercatat pada sebuah tenant; satu baris per orang, bukan per posisi.
 *
 * `core_membership_id` menunjuk keanggotaan tenant milik Core dan boleh kosong: pekerja yang
 * tidak pernah membuka aplikasi tetap harus tercatat, dan menuntut akun untuk setiap orang
 * akan membuat daftar pekerja menjadi daftar pengguna — dua hal yang tidak sama.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $personnel_number
 * @property string $name
 * @property ?string $email
 * @property ?string $core_membership_id
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Worker extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'hr_workers';

    protected $fillable = ['tenant_id', 'creation_key', 'personnel_number', 'name', 'email', 'core_membership_id'];

    /**
     * `deleted_at` tidak ikut ke jawaban endpoint.
     *
     * Daftar pekerja dipulangkan sebagai model apa adanya, jadi setiap kolom baru langsung
     * menjadi kunci baru pada jawaban API. Soft delete adalah urusan penyimpanan — ia menjaga
     * baris yang masih dirujuk penugasan tetap ada — dan bukan bagian dari yang dijanjikan
     * endpoint ini. Menyembunyikannya membuat penambahan kolomnya tidak mengubah bentuk
     * jawaban sama sekali.
     *
     * @var list<string>
     */
    protected $hidden = ['deleted_at'];

    /** @return HasMany<WorkerPositionAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkerPositionAssignment::class, 'worker_id');
    }
}
