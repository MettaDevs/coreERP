<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_buku_penyusutan', function (Blueprint $table): void {
            // Bridge ke Finance harus dipilih secara sadar; V1 belum memiliki kontrak
            // posting, COA, atau ownership akun dari Finance.
            $table->boolean('export_to_backoffice')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('m_buku_penyusutan', function (Blueprint $table): void {
            $table->boolean('export_to_backoffice')->default(true)->change();
        });
    }
};
