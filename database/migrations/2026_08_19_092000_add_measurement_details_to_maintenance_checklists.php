<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengukuran checklist harus menunjuk satuan milik Core dan dapat membawa batas
     * prosedur. Kode satuan tetap disimpan sebagai snapshot tampilan agar work order
     * lama tidak berubah bila nama satuan di Core diperbarui.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('m_maintenance_checklist_template_line', 'unit_id')) {
            Schema::table('m_maintenance_checklist_template_line', function (Blueprint $table): void {
                $table->ulid('unit_id')->nullable()->after('unit');
            });
        }
        foreach (['min_value', 'max_value'] as $column) {
            if (! Schema::hasColumn('m_maintenance_checklist_template_line', $column)) {
                Schema::table('m_maintenance_checklist_template_line', function (Blueprint $table) use ($column): void {
                    $table->decimal($column, 18, 6)->nullable();
                });
            }
            if (! Schema::hasColumn('tr_pemeliharaan_aset_checklist', $column)) {
                Schema::table('tr_pemeliharaan_aset_checklist', function (Blueprint $table) use ($column): void {
                    $table->decimal($column, 18, 6)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('tr_pemeliharaan_aset_checklist', function (Blueprint $table): void {
            $table->dropColumn(['min_value', 'max_value']);
        });

        Schema::table('m_maintenance_checklist_template_line', function (Blueprint $table): void {
            $table->dropColumn(['unit_id', 'min_value', 'max_value']);
        });
    }
};
