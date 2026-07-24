<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dutyCodes = DB::table('security_duties')->where('app_id', 'procurement')->pluck('code');

        DB::table('security_role_duties')->whereIn('duty_code', $dutyCodes)->delete();
        DB::table('tenant_app_entitlements')->where('app_id', 'procurement')->delete();
        DB::table('app_placements')->where('app_id', 'procurement')->delete();
        DB::table('apps')->where('id', 'procurement')->delete();
    }

    public function down(): void
    {
        // Katalog Procurement sengaja tidak dipulihkan; app ini sudah dikeluarkan dari development platform.
    }
};
