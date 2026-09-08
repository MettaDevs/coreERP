<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Group aset adalah sumbu finansial; padanan "Fixed asset group" di Dynamics 365
        // F&O, yang membawa perlakuan akuntansi dan bukan sekadar pengelompokan tampilan.
        Schema::table('m_group_aset', function (Blueprint $table): void {
            // Kelompok harta berwujud menurut PMK. Menentukan masa manfaat dan tarif
            // fiskal, jadi disimpan pada group dan bukan diturunkan dari jenis aset.
            $table->string('tipe_harta', 30)->nullable();
            // Pembeda kasar untuk pelaporan: berwujud, tidak berwujud, hak guna, atau
            // aset bernilai rendah.
            $table->string('major_type', 30)->nullable();
            // Di bawah nilai ini perolehan dibebankan, tidak dikapitalisasi sebagai aset.
            $table->decimal('capitalization_threshold', 18, 2)->nullable();
            // Lapisan pembukuan yang boleh dipakai group ini, dipisah koma. Menjadi
            // penjaga saat baris matriks group x buku dibuat pada fase penyusutan.
            $table->string('posting_layers', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_group_aset', function (Blueprint $table): void {
            $table->dropColumn(['tipe_harta', 'major_type', 'capitalization_threshold', 'posting_layers']);
        });
    }
};
