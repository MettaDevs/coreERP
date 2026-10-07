<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohB\Models;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Baris pesanan bahan uji untuk membuktikan penggabungan analitik lintas module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property string $org_unit_id
 * @property string $currency_code
 * @property string $amount
 */
#[DataClassification(DataClass::CustomerContent)]
final class Order extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    public const FIELD_CAPTIONS = [
        'legal_entity_id' => 'Entitas legal',
        'org_unit_id' => 'Unit kerja',
        'currency_code' => 'Mata uang',
        'amount' => 'Nilai pesanan',
    ];

    public const FIELD_HIDDEN = [
        'legal_entity_id' => 'Ditetapkan oleh sumber transaksi.',
    ];

    protected $table = 'contoh_b_tr_orders';

    protected $fillable = ['tenant_id', 'legal_entity_id', 'org_unit_id', 'currency_code', 'amount'];
}
