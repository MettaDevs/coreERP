<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_app_entitlements')
            ->where('status', '!=', 'active')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        // Entitlement status is intentionally no longer downgraded.
    }
};
