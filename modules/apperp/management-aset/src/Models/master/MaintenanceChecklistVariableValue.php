<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pilihan nilai sebuah variabel checklist maintenance.
 *
 * `line_number` desimal mengikuti baris template: urutan boleh disisipi tanpa menomori ulang.
 * Ia di-cast `decimal:1`, jadi Eloquent memulangkannya sebagai string dan bukan float.
 * Tabelnya tanpa soft delete, jadi tanpa `deleted_at`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $variable_id
 * @property string $line_number
 * @property string $value
 * @property string $result_code
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MaintenanceChecklistVariableValue extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_m_maintenance_checklist_variable_value';

    protected $fillable = ['tenant_id', 'variable_id', 'line_number', 'value', 'result_code'];

    protected function casts(): array
    {
        return ['line_number' => 'decimal:1'];
    }
}
