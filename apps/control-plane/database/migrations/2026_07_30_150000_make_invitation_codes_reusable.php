<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_codes', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
            $table->dropColumn('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('invitation_codes', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
