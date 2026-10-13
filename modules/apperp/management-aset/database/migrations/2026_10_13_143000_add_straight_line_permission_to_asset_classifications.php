<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_m_kelompok_harta_fiskal', function (Blueprint $table): void {
            $table->boolean('allow_straight_line')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('aset_m_kelompok_harta_fiskal', function (Blueprint $table): void {
            $table->dropColumn('allow_straight_line');
        });
    }
};
