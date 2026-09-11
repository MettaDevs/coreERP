<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Referensi regulasi fiskal yang dimiliki tenant dan berversi berdasarkan tanggal
 * berlaku. Ia bukan master bisnis dan karena itu tidak memakai Number Sequence.
 *
 * Tipe kolom di bawah mengikuti migration pembuat tabelnya dan cast di kelas ini:
 * dua kolom persentase adalah `decimal:4`, jadi Eloquent memulangkannya sebagai
 * string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_key
 * @property string $jurisdiction
 * @property string $label
 * @property ?string $regulation_reference
 * @property Carbon $effective_from
 * @property ?Carbon $effective_to
 * @property ?int $useful_life_years
 * @property ?string $straight_line_rate_percent
 * @property ?string $reducing_balance_rate_percent
 * @property bool $allow_reducing_balance
 * @property bool $depreciable
 * @property bool $aktif
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class KelompokHartaFiskal extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'aset_m_kelompok_harta_fiskal';

    protected $fillable = [
        'tenant_id', 'template_key', 'jurisdiction', 'label', 'regulation_reference',
        'effective_from', 'effective_to', 'useful_life_years',
        'straight_line_rate_percent', 'reducing_balance_rate_percent',
        'allow_reducing_balance', 'depreciable', 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'useful_life_years' => 'integer',
            'straight_line_rate_percent' => 'decimal:4',
            'reducing_balance_rate_percent' => 'decimal:4',
            'allow_reducing_balance' => 'boolean',
            'depreciable' => 'boolean',
            'aktif' => 'boolean',
        ];
    }
}
