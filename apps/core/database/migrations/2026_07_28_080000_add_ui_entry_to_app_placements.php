<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_placements', function (Blueprint $table) {
            $table->string('ui_entry', 2048)->nullable()->after('placement');
        });

        DB::table('app_placements')->update([
            'ui_entry' => DB::raw('(select ui_entry from apps where apps.id = app_placements.app_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('app_placements', function (Blueprint $table) {
            $table->dropColumn('ui_entry');
        });
    }
};
