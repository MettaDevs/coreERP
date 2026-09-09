<?php

namespace Modules\Apperp\ManagementAset\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Perilaku bersama master Management Aset: kode diterbitkan Number Sequence Core,
 * setiap record dimiliki satu tenant, dan arsip memakai soft delete agar record
 * yang direferensikan data turunan tidak hilang secara fisik.
 *
 * Kolom di bawah adalah bentuk dasar yang dibuat setiap migration master (lihat
 * `createMaster()` pada migration tabel master aset): analisa statis tidak dapat
 * menyimpulkannya dari Eloquent, sedangkan controller master membacanya sebagai
 * atribut biasa. Kolom tambahan milik satu master disebutkan pada modelnya sendiri.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $nama
 * @property ?string $keterangan
 * @property bool $aktif
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
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
