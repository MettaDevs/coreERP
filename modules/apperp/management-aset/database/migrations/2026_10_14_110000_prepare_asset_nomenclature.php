<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_m_kelompok_harta_fiskal', fn (Blueprint $table) => $table->string('kode', 10)->nullable());
    }

    public function down(): void
    {
        Schema::table('aset_m_kelompok_harta_fiskal', fn (Blueprint $table) => $table->dropColumn('kode'));
    }
};
