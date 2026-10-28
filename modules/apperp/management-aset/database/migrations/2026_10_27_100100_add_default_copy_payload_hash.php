<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_m_maintenance_job_type_default', fn (Blueprint $table) => $table->char('creation_payload_hash', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('aset_m_maintenance_job_type_default', fn (Blueprint $table) => $table->dropColumn('creation_payload_hash'));
    }
};
