<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_number_sequence_references', function (Blueprint $table): void {
            $table->string('default_prefix', 10)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('app_number_sequence_references', function (Blueprint $table): void {
            $table->dropColumn('default_prefix');
        });
    }
};
