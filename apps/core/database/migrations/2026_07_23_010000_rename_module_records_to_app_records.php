<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        Schema::rename('modules', 'apps');
        Schema::rename('tenant_module_entitlements', 'tenant_app_entitlements');
        Schema::rename('module_placements', 'app_placements');
        Schema::rename('module_installations', 'app_installations');
        Schema::rename('module_entry_points', 'app_entry_points');

        Schema::table('tenant_app_entitlements', fn (Blueprint $table) => $table->renameColumn('module_id', 'app_id'));
        Schema::table('app_placements', fn (Blueprint $table) => $table->renameColumn('module_id', 'app_id'));
        Schema::table('app_installations', fn (Blueprint $table) => $table->renameColumn('module_placement_id', 'app_placement_id'));
        Schema::table('app_entry_points', fn (Blueprint $table) => $table->renameColumn('module_id', 'app_id'));
        Schema::table('permissions', fn (Blueprint $table) => $table->renameColumn('module_id', 'app_id'));
        Schema::table('security_privileges', fn (Blueprint $table) => $table->renameColumn('module_id', 'app_id'));
        Schema::table('security_duties', fn (Blueprint $table) => $table->renameColumn('module_id', 'app_id'));
    }

    public function down(): void
    {
        if (! Schema::hasTable('apps')) {
            return;
        }

        Schema::table('security_duties', fn (Blueprint $table) => $table->renameColumn('app_id', 'module_id'));
        Schema::table('security_privileges', fn (Blueprint $table) => $table->renameColumn('app_id', 'module_id'));
        Schema::table('permissions', fn (Blueprint $table) => $table->renameColumn('app_id', 'module_id'));
        Schema::table('app_entry_points', fn (Blueprint $table) => $table->renameColumn('app_id', 'module_id'));
        Schema::table('app_installations', fn (Blueprint $table) => $table->renameColumn('app_placement_id', 'module_placement_id'));
        Schema::table('app_placements', fn (Blueprint $table) => $table->renameColumn('app_id', 'module_id'));
        Schema::table('tenant_app_entitlements', fn (Blueprint $table) => $table->renameColumn('app_id', 'module_id'));

        Schema::rename('app_entry_points', 'module_entry_points');
        Schema::rename('app_installations', 'module_installations');
        Schema::rename('app_placements', 'module_placements');
        Schema::rename('tenant_app_entitlements', 'tenant_module_entitlements');
        Schema::rename('apps', 'modules');
    }
};
