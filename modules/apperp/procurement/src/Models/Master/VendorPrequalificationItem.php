<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models\Master;

use App\Platform\Modules\Contracts\DataClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\Procurement\Models\MasterData;

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
 * @property int $version
 */
class VendorPrequalificationItem extends MasterData
{
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
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VendorPrequalification, $this>
     */
    public function prequalification(): BelongsTo
    {
        return $this->belongsTo(VendorPrequalification::class, 'prakualifikasi_id');
    }
}
