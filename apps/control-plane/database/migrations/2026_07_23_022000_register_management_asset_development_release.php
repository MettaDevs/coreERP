<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('apps')->where('id', 'management-asset')->exists()) {
            return;
        }

        $deployments = DB::table('tenant_deployments as deployments')
            ->join('tenant_app_entitlements as entitlements', 'entitlements.tenant_id', '=', 'deployments.tenant_id')
            ->where('entitlements.app_id', 'management-asset')
            ->where('entitlements.status', 'active')
            ->where('deployments.status', 'active')
            ->get(['deployments.placement', 'deployments.profile']);

        foreach ($deployments as $deployment) {
            DB::table('app_placements')->updateOrInsert(
                ['app_id' => 'management-asset', 'placement' => $deployment->placement],
                [
                    'id' => (string) Str::ulid(),
                    'release_version' => '0.1.0',
                    'profile' => $deployment->profile,
                    'artifact_status' => 'placed',
                    'migration_status' => 'succeeded',
                    'runtime_status' => 'ready',
                    'ready_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('app_placements')->where('app_id', 'management-asset')->delete();
    }
};
