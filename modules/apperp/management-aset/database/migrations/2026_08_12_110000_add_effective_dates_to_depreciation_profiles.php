<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_profil_penyusutan', function (Blueprint $table): void {
            // Null berarti profil legacy yang tetap berlaku tanpa batas tanggal.
            // Profil baru dapat dibuat sebagai versi dengan rentang berlaku sendiri.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->index(['tenant_id', 'effective_from', 'effective_to'], 'profil_penyusutan_effective_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::table('m_profil_penyusutan', function (Blueprint $table): void {
            $table->dropIndex('profil_penyusutan_effective_dates_idx');
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
