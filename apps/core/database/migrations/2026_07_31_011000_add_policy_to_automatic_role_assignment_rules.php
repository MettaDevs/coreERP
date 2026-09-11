<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automatic_role_assignment_rules', function (Blueprint $table): void {
            $table->string('policy_code')->nullable()->after('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('automatic_role_assignment_rules', function (Blueprint $table): void {
            $table->dropColumn('policy_code');
        });
    }
};
