<?php

namespace Modules\Apperp\HumanResources\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Jabatan: pekerjaan yang dijelaskan sekali dan dipakai berulang oleh banyak posisi.
 *
 * Jabatan tidak menempel pada unit kerja mana pun — yang menempel pada unit kerja adalah
 * posisi. Itu sebabnya daftar jabatan tidak pernah disaring lingkup kebijakan data: menyaring
 * jabatan menurut unit kerja berarti menyaring sesuatu yang memang tidak punya unit kerja.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Job extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'hr_jobs';

    protected $fillable = ['tenant_id', 'creation_key', 'code', 'name', 'description'];

    /**
     * Alasannya sama dengan pada `Worker`: bentuk jawaban endpoint tidak boleh ikut berubah
     * hanya karena tabelnya mendapat kolom soft delete.
     *
     * @var list<string>
     */
    protected $hidden = ['deleted_at'];
}
