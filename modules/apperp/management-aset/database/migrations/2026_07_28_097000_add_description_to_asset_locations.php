<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_lokasi_aset', function (Blueprint $table): void {
            $table->text('keterangan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_lokasi_aset', function (Blueprint $table): void {
            $table->dropColumn('keterangan');
        });
    }
};
