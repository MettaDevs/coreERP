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
 * @property string $kategori
 * @property int $urutan
 * @property string $nama_dokumen
 * @property bool $ada
 * @property ?string $keterangan
 * @property int $version
 */
class VendorPrequalificationDocument extends MasterData
{
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
