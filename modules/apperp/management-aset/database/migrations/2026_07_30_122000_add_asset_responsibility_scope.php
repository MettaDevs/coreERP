<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tr_penerimaan_aset', 'responsible_org_unit_id')) {
            return;
        }
        Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
            $table->ulid('responsible_org_unit_id')->nullable()->after('legal_entity_id');
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id'], 'asset_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
            $table->dropIndex('asset_scope_index');
            $table->dropColumn('responsible_org_unit_id');
        });
    }
};
