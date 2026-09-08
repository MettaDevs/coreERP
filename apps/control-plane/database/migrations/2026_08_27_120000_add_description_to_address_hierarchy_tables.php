<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ref_regencies', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('ref_provinces', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('ref_regencies', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('ref_provinces', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
