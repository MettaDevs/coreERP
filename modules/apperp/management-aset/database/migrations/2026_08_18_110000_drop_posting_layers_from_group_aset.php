<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_group_aset', function (Blueprint $table): void {
            // Lapisan pembukuan adalah sifat buku, bukan sifat group. Di Dynamics 365 F&O
            // ia menempel pada Book, dan di app ini pun satu-satunya kolom yang benar
            // dibaca adalah `m_buku_penyusutan.posting_layer`. Kolom ini tidak pernah
            // dipakai untuk mengambil keputusan apa pun; membiarkannya hanya membuat
            // konfigurator mengisi tempat yang salah lalu heran karena tak terjadi apa-apa.
            $table->dropColumn('posting_layers');
        });
    }

    public function down(): void
    {
        Schema::table('m_group_aset', function (Blueprint $table): void {
            $table->string('posting_layers', 120)->nullable();
        });
    }
};
