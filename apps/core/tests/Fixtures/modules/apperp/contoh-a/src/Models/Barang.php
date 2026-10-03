<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Models;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
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
#[DataClassification(DataClass::CustomerContent)]
final class Barang extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /** Nama tampilan kolom untuk katalog field (K-30), dibaca dataset analitik bahan uji. */
    public const FIELD_CAPTIONS = [
        'kode' => 'Kode barang',
        'nama' => 'Nama barang',
        'bawaan' => 'Barang bawaan',
    ];

    protected $table = 'contoh_a_m_barang';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'bawaan'];

    protected $casts = ['bawaan' => 'boolean'];
}
