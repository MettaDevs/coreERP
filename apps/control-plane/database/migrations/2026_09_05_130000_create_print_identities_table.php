<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identitas cetak: apa yang tampil di kop dan footer dokumen sebuah organisasi.
        // Padanan Company Information di Business Central, tetapi per legal entity, dan
        // logo adalah daftar berposisi — bukan satu gambar — karena kop instansi
        // pemerintah memuat lambang daerah di kiri dan logo instansi di kanan.
        //
        // Operating unit boleh punya identitas sendiri (puskesmas di bawah dinas);
        // saat mencetak, yang paling spesifik yang dipakai.
        Schema::create('print_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();
            // Kosong berarti memakai nama organisasi.
            $table->string('display_name', 200)->nullable();
            // Baris di atas nama pada kop instansi: "PEMERINTAH KABUPATEN BADUNG", "DINAS KESEHATAN".
            $table->json('parent_lines');
            // Alamat, telepon, WhatsApp, email, dan laman TIDAK ada di sini: kop membacanya
            // dari buku alamat party organisasi (party_locations, electronic_addresses),
            // supaya satu alamat hanya diubah di satu tempat.
            $table->string('tax_id', 60)->nullable();
            $table->string('registration_id', 60)->nullable();
            $table->text('footer_text')->nullable();
            // [{id, position: kiri|tengah|kanan, path, width_mm, original_name, size}], berurutan.
            $table->json('logos');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_identities');
    }
};
