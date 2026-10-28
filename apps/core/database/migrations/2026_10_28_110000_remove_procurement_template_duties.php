<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('security_role_duties')
            ->whereIn('duty_code', [
                'procurement.example.access',
                'procurement.dashboard.manage',
                'procurement.laporan.manage',
                'procurement.master.manage',
                'procurement.transaksi.manage',
            ])
            ->delete();
    }

    public function down(): void {}
};
