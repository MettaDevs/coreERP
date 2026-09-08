<?php

namespace Modules\Apperp\ManagementAset\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perilaku bersama master Management Aset: kode diterbitkan Number Sequence Core,
 * setiap record dimiliki satu tenant, dan arsip memakai soft delete agar record
 * yang direferensikan data turunan tidak hilang secara fisik.
 */
abstract class MasterData extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $fillable = ['tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }
}
