<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Kebijakan jurnal perolehan satu entitas legal, berlaku mulai suatu tanggal.
 *
 * `direct_payable`: penerimaan aset langsung menjadi hutang, dan posting perolehan itulah fakturnya.
 * `clearing`: penerimaan dicatat ke akun perantara, lalu faktur di aplikasi finance menutupnya.
 * Padanannya *Accrue liability on product receipt* di Dynamics 365 F&O.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property string $mode
 * @property Carbon $effective_from
 */
class FinanceSettlementMode extends Model
{
    use HasUlids;

    public const DIRECT_PAYABLE = 'direct_payable';

    public const CLEARING = 'clearing';

    public const MODES = [self::DIRECT_PAYABLE, self::CLEARING];

    /** Mode yang dipakai entitas legal yang belum punya baris sama sekali (K-10). */
    public const DEFAULT = self::DIRECT_PAYABLE;

    protected $fillable = ['tenant_id', 'legal_entity_id', 'mode', 'effective_from'];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }
}
