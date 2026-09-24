<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Kiriman satu periode penyusutan ke backoffice.
 *
 * `posting_id` adalah identitas kiriman yang dipegang app ini, sedangkan
 * `external_reference` diisi backoffice setelah menerima. Satu periode hanya boleh punya satu
 * baris di sini; itulah yang menahan penjurnalan ganda.
 *
 * **Tidak lagi ditulis sejak feed posting finance area 11** (TODO 11.4): penyusutan sampai ke
 * aplikasi finance lewat proses "Post penyusutan" (`DepreciationPosting`). Tabel dan riwayatnya
 * dibiarkan dan tetap dapat dibaca; penghapusannya diputuskan terpisah.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $posting_id
 * @property string $depreciation_period_id
 * @property array<string, mixed> $payload
 * @property Carbon $finalized_at
 * @property ?Carbon $acknowledged_at
 * @property ?string $external_reference
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'finalized_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DepreciationPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(DepreciationPeriod::class, 'depreciation_period_id');
    }
}
