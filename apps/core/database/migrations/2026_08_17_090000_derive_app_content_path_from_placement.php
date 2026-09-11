<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Path konten UI berhenti disimpan dan mulai diturunkan dari (app_id, placement).
 *
 * Nilai yang tersimpan bisa basi — nilai dev pernah menunjuk IP DHCP lama — dan
 * disalin dari level app ke setiap placement, sehingga dua placement dari app
 * yang sama memperoleh path identik. Yang tersisa dari kolom lama hanyalah satu
 * informasi yang benar-benar milik app: apakah app ini punya UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->boolean('has_ui')->default(false)->after('database_name');
        });

        DB::table('apps')->whereNotNull('ui_entry')->update(['has_ui' => true]);

        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('ui_entry');
        });

        Schema::table('app_placements', function (Blueprint $table) {
            $table->dropColumn('ui_entry');
        });
    }

    /**
     * Kolom dikembalikan kosong. Nilai lama tidak direkonstruksi karena memang
     * tidak dapat dipercaya — itulah alasan kolom ini dihapus.
     */
    public function down(): void
    {
        Schema::table('app_placements', function (Blueprint $table) {
            $table->string('ui_entry', 2048)->nullable()->after('placement');
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->string('ui_entry', 2048)->nullable()->after('database_name');
            $table->dropColumn('has_ui');
        });
    }
};
