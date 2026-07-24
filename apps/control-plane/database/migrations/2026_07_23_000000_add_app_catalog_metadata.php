<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = Schema::hasTable('apps') ? 'apps' : 'modules';

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('ui_entry', 2048)->nullable()->after('database_name');
            $table->string('repository_url', 2048)->nullable()->after('ui_entry');
            $table->string('contract_url', 2048)->nullable()->after('repository_url');
        });
    }

    public function down(): void
    {
        $tableName = Schema::hasTable('apps') ? 'apps' : 'modules';

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn(['ui_entry', 'repository_url', 'contract_url']);
        });
    }
};
