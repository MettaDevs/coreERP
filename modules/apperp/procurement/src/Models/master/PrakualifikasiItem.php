<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\master;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $prakualifikasi_id
 * @property int $urutan
 * @property ?string $jenis_item
 * @property ?string $kode_item
 * @property string $nama_item
 * @property ?string $satuan
 * @property ?string $kesediaan
 * @property ?string $keterangan
 */
#[DataClassification(DataClass::CustomerContent)]
class PrakualifikasiItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_item' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_prakualifikasi_item';

    protected $fillable = [
        'tenant_id',
        'prakualifikasi_id',
        'urutan',
        'jenis_item',
        'kode_item',
        'nama_item',
        'satuan',
        'kesediaan',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Prakualifikasi, $this>
     */
    public function prakualifikasi(): BelongsTo
    {
        return $this->belongsTo(Prakualifikasi::class, 'prakualifikasi_id');
    }
}
