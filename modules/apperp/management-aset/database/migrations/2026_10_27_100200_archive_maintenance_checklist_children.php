<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        ['aset_m_maintenance_checklist_variable_value', 'variable_id', 'm_mnt_var_value_line_uq'],
        ['aset_m_maintenance_checklist_template_line', 'template_id', 'm_mnt_tpl_line_number_uq'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as [$name, $owner, $index]) {
            Schema::table($name, function (Blueprint $table) use ($index): void {
                $table->softDeletes();
                $table->dropUnique($index);
            });
            DB::statement('CREATE UNIQUE INDEX '.$index.'_active ON '.$name.' (tenant_id, '.$owner.', line_number) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as [$name, $owner, $index]) {
            Schema::table($name, fn (Blueprint $table) => $table->unique(['tenant_id', $owner, 'line_number'], $index));
            DB::statement('DROP INDEX '.$index.'_active');
            Schema::table($name, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
