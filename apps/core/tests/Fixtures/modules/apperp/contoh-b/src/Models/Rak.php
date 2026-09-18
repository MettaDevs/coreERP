<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohB\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model contoh. Ia ada supaya penjaga batas punya sesuatu untuk diuji, bukan supaya
 * ada yang memakainya. Bentuknya sengaja mengikuti aturan yang berlaku untuk module
 * sungguhan: nama tabel berawalan module, `tenant_id` pada setiap baris, dan
 * penghapusan lunak.
 *
 * Properti didaftarkan supaya analisa statis tahu bentuk barisnya. Tanpa itu, setiap
 * pembacaan kolom dilaporkan sebagai properti yang tidak ada — dan laporan seperti itu
 * mudah dianggap kebisingan, lalu penemuan yang sungguhan ikut diabaikan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kode
 * @property string $nama
 * @property bool $bawaan
 */
final class Rak extends Model
{
    use HasUlids;
    use MilikTenant;
    use SoftDeletes;

    protected $table = 'contoh_b_m_rak';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'bawaan'];

    protected $casts = ['bawaan' => 'boolean'];
}
