<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'phone_updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('phone_updated_at')->nullable()->after('phone_number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'phone_updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('phone_updated_at');
            });
        }
    }
};
