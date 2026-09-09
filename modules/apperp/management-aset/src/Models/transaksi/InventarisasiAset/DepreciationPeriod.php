<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu periode penyusutan sebuah buku aset.
 *
 * `reverses_period_id` yang terisi menandakan baris pembalik, bukan periode baru: index unik
 * parsial di database mengizinkan satu periode asli per `period_ends_on` dan satu pembalik
 * per periode yang dibalik. `legal_entity_id` dan `usage_org_unit_id` disalin ke sini supaya
 * penyaringan wewenang organisasi tidak perlu menelusuri kembali ke aset.
 */
class DepreciationPeriod extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_penyusutan_aset';

    protected $fillable = [
        'tenant_id', 'asset_book_id', 'legal_entity_id', 'usage_org_unit_id',
        'period_starts_on', 'period_ends_on', 'amount', 'status', 'reverses_period_id',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function assetBook(): BelongsTo
    {
        return $this->belongsTo(AssetBook::class, 'asset_book_id');
    }
}
