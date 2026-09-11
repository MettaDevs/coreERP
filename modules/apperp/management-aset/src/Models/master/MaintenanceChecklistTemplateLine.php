<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Baris template checklist maintenance.
 *
 * `line_number` desimal, bukan bilangan bulat, supaya langkah 1.5 dapat disisipkan di antara
 * 1 dan 2 tanpa menomori ulang prosedur yang sudah dicetak. `unit` adalah snapshot kode
 * satuan untuk tampilan, sedangkan `unit_id` yang menunjuk satuan milik Core.
 *
 * `line_number`, `min_value`, dan `max_value` di-cast desimal, jadi Eloquent memulangkannya
 * sebagai string dan bukan float. Tabelnya tanpa soft delete, jadi tanpa `deleted_at`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_id
 * @property string $line_number
 * @property string $type
 * @property ?string $variable_id
 * @property ?string $nested_template_id
 * @property ?string $unit
 * @property ?string $unit_id
 * @property string $nama
 * @property ?string $instruksi
 * @property bool $wajib
 * @property ?string $min_value
 * @property ?string $max_value
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MaintenanceChecklistTemplateLine extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_m_maintenance_checklist_template_line';

    protected $fillable = [
        'tenant_id', 'template_id', 'line_number', 'type', 'variable_id', 'nested_template_id',
        'unit', 'unit_id', 'nama', 'instruksi', 'wajib', 'min_value', 'max_value',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'decimal:1',
            'wajib' => 'boolean',
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
        ];
    }
}
