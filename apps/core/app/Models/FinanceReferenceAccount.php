<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu akun milik aplikasi finance pelanggan (K-05).
 *
 * `external_id` adalah identitas akun di sistem pemiliknya dan tidak pernah berubah; `code` dan
 * `name` adalah yang dilihat orang dan boleh berubah lewat impor ulang. Pemetaan posting menunjuk
 * `id` baris ini, jadi ganti nomor atau nama tidak memutus pemetaan apa pun.
 *
 * `type` menentukan dimensi yang dibawa baris jurnal (K-09): akun neraca hanya business unit, akun
 * laba rugi business unit dan department.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $legal_entity_id
 * @property string $external_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $active
 * @property ?Carbon $synced_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FinanceReferenceAccount extends Model
{
    use HasUlids;

    public const BALANCE_SHEET = 'balance_sheet';

    public const PROFIT_LOSS = 'profit_loss';

    public const TYPES = [self::BALANCE_SHEET, self::PROFIT_LOSS];

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'external_id', 'code', 'name', 'type', 'active', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'synced_at' => 'datetime'];
    }
}
