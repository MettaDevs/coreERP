<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_group_aset', function (Blueprint $table): void {
            // `major_type` menyimpan sifat harta: berwujud, tidak berwujud, hak guna.
            // Klasifikasi itu tidak menggerakkan apa pun di modul ini — akun ditentukan
            // posting profile yang di-key oleh group, dan V1 belum membuat jurnal GL.
            // Ia juga konstan per group, jadi tidak pernah memisahkan apa pun yang
            // belum dipisahkan oleh group itu sendiri. Dibuang, bukan dinamai ulang.
            $table->dropColumn('major_type');

            // Yang tetap tinggal adalah pertanyaan yang punya pembaca di sini: apakah
            // perolehan disajikan di neraca. Padanan `Property type` di F&O, dan padanan
            // pembedaan intrakomptabel/ekstrakomptabel pada penatausahaan barang di
            // Indonesia. Berpasangan dengan `capitalization_threshold`.
            $table->string('property_type', 30)->nullable();

            // Lokasi bawaan saat aset diterima. Hanya nilai awal: lokasi yang berlaku
            // tetap hidup pada aset dan penempatannya, tidak pernah dibaca ulang dari group.
            $table->ulid('asset_location_id')->nullable()->index();
            $table->foreign(['tenant_id', 'asset_location_id'])
                ->references(['tenant_id', 'id'])
                ->on('m_lokasi_aset')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('m_group_aset', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'asset_location_id']);
            $table->dropColumn(['property_type', 'asset_location_id']);
            $table->string('major_type', 30)->nullable();
        });
    }
};
