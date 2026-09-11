<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('security_role_duties')->where('duty_code', 'management-aset.satuan.manage')->delete();
    }

    public function down(): void {}
};
