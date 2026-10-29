<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('aset_tr_perencanaan_aset_details', 'estimate_pending')) {
            Schema::table('aset_tr_perencanaan_aset_details', function (Blueprint $table): void {
                $table->boolean('estimate_pending')->default(false);
            });
        }
        DB::statement('ALTER TABLE aset_tr_perencanaan_aset_details ALTER COLUMN estimated_unit_price SET DEFAULT 0, ALTER COLUMN estimated_total_price SET DEFAULT 0');
        DB::table('aset_tr_perencanaan_aset_details')->whereNull('estimated_unit_price')->update([
            'estimate_pending' => true,
            'estimated_unit_price' => 0,
            'estimated_total_price' => 0,
        ]);
        DB::table('aset_tr_perencanaan_aset_details')->whereNull('estimated_total_price')->update([
            'estimated_total_price' => DB::raw('ROUND(quantity * estimated_unit_price, 2)'),
        ]);
        DB::statement('CREATE INDEX IF NOT EXISTS asset_plan_pending_estimates ON aset_tr_perencanaan_aset_details (tenant_id, planning_id) WHERE deleted_at IS NULL AND estimate_pending = true AND estimated_unit_price = 0');
    }

    public function down(): void {}
};
