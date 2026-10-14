<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_number_sequences', function (Blueprint $table): void {
            $table->boolean('allow_automatic')->default(true);
        });
        Schema::table('app_number_sequence_references', function (Blueprint $table): void {
            $table->jsonb('default_settings')->nullable();
        });
        Schema::table('number_sequence_issues', function (Blueprint $table): void {
            $table->unique(['sequence_id', 'scope_key', 'formatted_value'], 'number_sequence_issues_value_unique');
        });
    }

    public function down(): void
    {
        Schema::table('number_sequence_issues', fn (Blueprint $table) => $table->dropUnique('number_sequence_issues_value_unique'));
        Schema::table('app_number_sequence_references', fn (Blueprint $table) => $table->dropColumn('default_settings'));
        Schema::table('tenant_number_sequences', fn (Blueprint $table) => $table->dropColumn('allow_automatic'));
    }
};
