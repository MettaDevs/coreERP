<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal buku aset ditutup saat aset dilepas.
 *
 * `status` sudah ada dan sudah berisi `active`, tetapi tidak pernah ada yang menutupnya.
 * Tanggalnya disimpan terpisah karena yang dibutuhkan laporan bukan sekadar "sudah
 * ditutup", melainkan sejak periode mana buku ini berhenti menyusut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            $table->date('closed_on')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            $table->dropColumn('closed_on');
        });
    }
};
