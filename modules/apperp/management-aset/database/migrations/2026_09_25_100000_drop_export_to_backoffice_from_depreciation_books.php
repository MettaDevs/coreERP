<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `export_to_backoffice` dibuang (K-15, TODO 8.4.3). Sejak saklarnya dilebur ke `posting_layer`
 * (migration 2026_09_23_110000, rilis 0.9.0), tidak ada kode yang membaca atau menulisnya. Kolomnya
 * dibiarkan satu rilis supaya rilis 0.8.0 tetap berjalan di atas skema 0.9.0 (aturan N-1); rilis yang
 * memuat migration ini hanya boleh dibatalkan ke 0.9.0 atau sesudahnya.
 *
 * @kontrak Rilis 0.9.0 berhenti membaca dan menulis `export_to_backoffice` (TODO 8.4.2, PR #176).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_m_buku_penyusutan', function (Blueprint $table): void {
            $table->dropColumn('export_to_backoffice');
        });
    }

    /** Kolomnya kembali dengan bawaan terakhirnya; nilai lama tidak dapat dipulihkan, dan tidak dibaca siapa pun. */
    public function down(): void
    {
        Schema::table('aset_m_buku_penyusutan', function (Blueprint $table): void {
            $table->boolean('export_to_backoffice')->default(false);
        });
    }
};
