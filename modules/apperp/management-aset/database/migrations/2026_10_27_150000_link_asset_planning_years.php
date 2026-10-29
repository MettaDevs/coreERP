<?php

use App\Platform\Modules\Contracts\PlanningYearDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_perencanaan_aset', function (Blueprint $table): void {
            $table->ulid('planning_year_id')->nullable();
            $table->foreign(['tenant_id', 'legal_entity_id', 'planning_year_id'], 'asset_plan_planning_year_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('planning_years')->restrictOnDelete();
        });
        DB::table('aset_tr_perencanaan_aset')->orderBy('id')->chunkById(100, function ($plans): void {
            foreach ($plans as $plan) {
                $year = app(PlanningYearDirectory::class)->importLegacy((string) $plan->tenant_id, (string) $plan->legal_entity_id, (int) $plan->planning_year);
                DB::table('aset_tr_perencanaan_aset')->where('id', $plan->id)->update(['planning_year_id' => $year['id']]);
            }
        });
    }

    public function down(): void {}
};
