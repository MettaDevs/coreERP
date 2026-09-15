<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keadaan lisensi situs di sisi admin.erp, untuk lisensi yang mengunci.
 *
 * Rancangannya di `docs/todo/lisensi-mengunci/README.md`. Lisensi berlaku 30 hari dan diperpanjang
 * lewat jawaban laporan agen, jadi admin.erp perlu mengingat tiga hal yang tidak dapat dibaca dari
 * laporan agen:
 *
 * - `license_issued_at` — kapan lisensi terakhir diterbitkan. Jeda perpanjangan dihitung dari sini;
 *   tanpanya setiap laporan yang masih membawa tanggal lama melahirkan lisensi baru.
 * - `license_valid_until` — tanggal berakhir lisensi terakhir yang diterbitkan. Laporan agen menyebut
 *   yang *terpasang*; kolom ini menyebut yang *dikirim*. Selisih keduanya adalah lisensi yang gagal
 *   dipasang.
 * - `license_suspended_at` — operator menghentikan perpanjangan. Lisensi yang berjalan habis dengan
 *   sendirinya; server klien tidak disentuh.
 *
 * Seperti registry situs lainnya, tabel ini milik sisi pusat dan kolomnya kosong di database
 * lingkungan dan di server klien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('license_issued_at')->nullable();
            $table->date('license_valid_until')->nullable();
            $table->timestamp('license_suspended_at')->nullable();
        });

        // Diterbitkan berarti ada tanggal berakhirnya, dan sebaliknya. Keduanya ditulis penerbit yang
        // sama pada satu tindakan; satu tanpa yang lain berarti ada penulis yang hanya tahu separuh
        // aturannya, dan jeda perpanjangan akan dihitung dari lisensi yang tidak pernah ada.
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_lisensi_berpasangan CHECK ((license_issued_at IS NULL) = (license_valid_until IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_lisensi_berpasangan');

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['license_issued_at', 'license_valid_until', 'license_suspended_at']);
        });
    }
};
