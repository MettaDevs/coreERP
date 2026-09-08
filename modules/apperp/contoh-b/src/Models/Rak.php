<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohB\Models;

use App\Support\Modules\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model contoh. Ia ada supaya penjaga batas punya sesuatu untuk diuji, bukan supaya
 * ada yang memakainya. Bentuknya sengaja mengikuti aturan yang berlaku untuk module
 * sungguhan: nama tabel berawalan module, `tenant_id` pada setiap baris, dan
 * penghapusan lunak.
 */
final class Rak extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'contoh_b_m_rak';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'bawaan'];

    protected $casts = ['bawaan' => 'boolean'];

    protected static function booted(): void
    {
        self::addGlobalScope(new TenantScope);
    }
}
