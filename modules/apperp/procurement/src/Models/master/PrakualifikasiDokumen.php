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
 * @property string $kategori
 * @property int $urutan
 * @property string $nama_dokumen
 * @property bool $ada
 * @property ?string $keterangan
 */
#[DataClassification(DataClass::CustomerContent)]
class PrakualifikasiDokumen extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_dokumen' => DataClass::CustomerContent,
    ];

    protected $table = 'procurement_prakualifikasi_dokumen';

    protected $fillable = [
        'tenant_id',
        'prakualifikasi_id',
        'kategori',
        'urutan',
        'nama_dokumen',
        'ada',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'ada' => 'boolean',
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
