<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pilihan nilai sebuah variabel checklist maintenance.
 *
 * `line_number` desimal mengikuti baris template: urutan boleh disisipi tanpa menomori ulang.
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
