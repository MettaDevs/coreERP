<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_entities', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->nullable()->after('organization_id');
        });

        $tenantByOrganization = DB::table('organizations')->pluck('tenant_id', 'id');
        foreach (DB::table('legal_entities')->get(['organization_id']) as $legalEntity) {
            DB::table('legal_entities')->where('organization_id', $legalEntity->organization_id)->update([
                'tenant_id' => $tenantByOrganization[$legalEntity->organization_id],
            ]);
        }

        Schema::table('legal_entities', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'company_code']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('code', 50)->nullable();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('legal_entities', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'company_code']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
