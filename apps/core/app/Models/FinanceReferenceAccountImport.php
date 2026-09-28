<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu impor daftar akun: diterapkan seluruhnya, atau ditolak seluruhnya bila ada baris yang salah.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $legal_entity_id
 * @property string $file_name
 * @property ?string $imported_by_user_id
 * @property string $status
 * @property int $created_count
 * @property int $updated_count
 * @property int $unchanged_count
 * @property int $missing_count
 * @property int $rejected_count
 * @property ?list<array{line: int, external_id: ?string, reason: string}> $rejected_rows
 * @property ?Carbon $created_at
 */
class FinanceReferenceAccountImport extends Model
{
    use HasUlids;

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'file_name', 'imported_by_user_id', 'status',
        'created_count', 'updated_count', 'unchanged_count', 'missing_count', 'rejected_count', 'rejected_rows',
    ];

    protected function casts(): array
    {
        return [
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'unchanged_count' => 'integer',
            'missing_count' => 'integer',
            'rejected_count' => 'integer',
            'rejected_rows' => 'array',
        ];
    }
}
