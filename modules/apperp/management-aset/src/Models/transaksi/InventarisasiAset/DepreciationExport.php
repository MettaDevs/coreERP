<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kiriman satu periode penyusutan ke backoffice.
 *
 * `posting_id` adalah identitas kiriman yang dipegang app ini, sedangkan
 * `external_reference` diisi backoffice setelah menerima. Satu periode hanya boleh punya satu
 * baris di sini; itulah yang menahan penjurnalan ganda.
 */
class DepreciationExport extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_export_penyusutan';

    protected $fillable = [
        'tenant_id', 'posting_id', 'depreciation_period_id', 'payload',
        'finalized_at', 'acknowledged_at', 'external_reference',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'finalized_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(DepreciationPeriod::class, 'depreciation_period_id');
    }
}
