<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu baris jurnal posting finance, dengan dua dimensi global sebagai kolom (K-07, TODO 6.3.9).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $finance_posting_id
 * @property int $line_no
 * @property ?string $account_id
 * @property ?string $account_external_id
 * @property ?string $account_code
 * @property string $debit
 * @property string $credit
 * @property ?string $description
 * @property ?string $org_unit_id
 * @property ?string $business_unit_code
 * @property ?string $department_code
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FinancePostingLine extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'finance_posting_id', 'line_no', 'account_id', 'account_external_id', 'account_code',
        'debit', 'credit', 'description', 'org_unit_id', 'business_unit_code', 'department_code',
    ];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }
}
