<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warna header aplikasi milik tenant: gradien dua warna dari kiri ke kanan. Kosong berarti header
 * bawaan. Keduanya diisi atau dikosongkan bersama, dan bentuknya selalu `#RRGGBB` — nilai ini
 * masuk ke CSS halaman, jadi database yang menolak bentuk lain, bukan hanya validasi permintaan.
 *
 * Kolom baru yang boleh kosong, jadi aman untuk rilis sebelumnya: kode lama tidak membacanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_brandings', function (Blueprint $table): void {
            $table->string('header_color_from', 7)->nullable();
            $table->string('header_color_to', 7)->nullable();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE tenant_brandings ADD CONSTRAINT tenant_brandings_warna_header_valid CHECK (
                (header_color_from IS NULL AND header_color_to IS NULL)
                OR (header_color_from ~ '^#[0-9a-f]{6}$' AND header_color_to ~ '^#[0-9a-f]{6}$')
            )
            SQL);
    }

    public function down(): void {}
};
