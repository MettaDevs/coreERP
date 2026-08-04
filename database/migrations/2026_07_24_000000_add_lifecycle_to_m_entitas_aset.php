<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_entitas_aset', function (Blueprint $table): void {
            $table->string('creation_key', 160);
            $table->softDeletes();
            $table->unique(['tenant_id', 'creation_key']);
        });
    }

    public function down(): void
    {
        Schema::table('m_entitas_aset', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'creation_key']);
            $table->dropColumn(['creation_key', 'deleted_at']);
        });
    }
};
