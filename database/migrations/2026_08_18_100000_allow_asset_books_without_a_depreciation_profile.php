<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            // Buku yang tidak menghitung tidak punya profil untuk dirujuk. Kolom ini
            // wajib sejak awal karena saat itu setiap buku pasti menyusut; sejak ambang
            // kapitalisasi dan group register-saja ada, buku tanpa profil menjadi
            // keadaan yang sah. Padanan "Calculate depreciation = No" pada Books di F&O.
            $table->ulid('depreciation_profile_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Buku tanpa profil harus dibereskan lebih dulu, kalau tidak kolomnya tidak
        // dapat dikembalikan menjadi wajib.
        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            $table->ulid('depreciation_profile_id')->nullable(false)->change();
        });
    }
};
