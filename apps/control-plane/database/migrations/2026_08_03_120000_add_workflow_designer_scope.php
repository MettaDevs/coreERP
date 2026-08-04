<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_types', function (Blueprint $table): void {
            $table->string('scope', 20)->default('legal_entity')->after('app_id');
        });

    }

    public function down(): void
    {
        Schema::table('workflow_types', fn (Blueprint $table) => $table->dropColumn('scope'));
    }
};
