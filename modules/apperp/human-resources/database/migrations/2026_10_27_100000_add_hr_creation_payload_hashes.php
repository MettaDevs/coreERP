<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_workers', 'hr_jobs', 'hr_positions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->char('creation_payload_hash', 64)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['hr_workers', 'hr_jobs', 'hr_positions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('creation_payload_hash'));
        }
    }
};
