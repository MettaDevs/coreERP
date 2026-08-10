<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN received_by_user_id TYPE varchar(64)');
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN custodian_user_id TYPE varchar(64)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN received_by_user_id TYPE char(26)');
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN custodian_user_id TYPE char(26)');
    }
};
