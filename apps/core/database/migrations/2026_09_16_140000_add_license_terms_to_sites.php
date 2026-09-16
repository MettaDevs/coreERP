<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Masa lisensi per situs, termasuk lisensi tanpa tanggal berakhir.
 *
 * Keputusan pemilik produk 16 September 2026: masa lisensi bawaan diatur di halaman Pengaturan dan boleh
 * ditimpa per situs, dan sebuah situs boleh diberi lisensi **permanen** — lisensi yang `valid_until`-nya
 * null, tidak pernah habis, dan tidak pernah diperpanjang. Yang membatasi app tetap daftar `apps` di
 * lisensi itu.
 *
 * - `license_perpetual` — setelan: lisensi yang diterbitkan untuk situs ini tidak bertanggal.
 * - `license_valid_days` dan `license_renew_before_days` — timpaan per situs; null berarti mengikuti
 *   bawaan di `console_settings`.
 * - `license_issued_perpetual` — keadaan: lisensi yang **terakhir diterbitkan** tidak bertanggal. Setelan
 *   dan keadaan dipisah karena keduanya berbeda selama satu jendela: operator yang mengubah situs permanen
 *   menjadi bertanggal baru berpengaruh pada penerbitan berikutnya, dan sampai saat itu yang terpasang di
 *   klien masih lisensi permanen.
 *
 * ## Constraint lisensi diganti, bukan dibuang
 *
 * Yang lama menuntut `license_issued_at` dan `license_valid_until` selalu ada atau tidak ada bersama-sama.
 * Lisensi permanen justru diterbitkan tanpa tanggal berakhir, jadi aturannya ditulis ulang: tetap tidak
 * boleh ada tanggal berakhir tanpa penerbitan, dan penerbitan tanpa tanggal hanya sah bila memang ditandai
 * permanen. Aturan barunya lebih longgar dari yang lama, sehingga kode rilis sebelumnya — yang hanya
 * menulis lisensi bertanggal — tetap berjalan di atas skema ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('license_perpetual')->default(false);
            $table->unsignedSmallInteger('license_valid_days')->nullable();
            $table->unsignedSmallInteger('license_renew_before_days')->nullable();
            $table->boolean('license_issued_perpetual')->default(false);
        });

        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_lisensi_berpasangan');
        DB::statement(<<<'SQL'
            ALTER TABLE sites ADD CONSTRAINT sites_lisensi_berpasangan CHECK (
                (license_issued_at IS NULL AND license_valid_until IS NULL AND license_issued_perpetual = false)
                OR (license_issued_at IS NOT NULL AND license_valid_until IS NOT NULL AND license_issued_perpetual = false)
                OR (license_issued_at IS NOT NULL AND license_valid_until IS NULL AND license_issued_perpetual = true)
            )
        SQL);

        // Angka yang tidak masuk akal ditolak database, bukan hanya oleh isian di layar. Masa lisensi
        // dibaca penjadwal perpanjangan; nol hari berarti lisensi yang lahir sudah habis, dan perpanjangan
        // yang tidak pernah lebih awal dari masanya berarti lisensi tidak pernah diperpanjang sama sekali.
        DB::statement(<<<'SQL'
            ALTER TABLE sites ADD CONSTRAINT sites_masa_lisensi_masuk_akal CHECK (
                (license_valid_days IS NULL OR license_valid_days BETWEEN 1 AND 3650)
                AND (license_renew_before_days IS NULL OR license_renew_before_days BETWEEN 1 AND 365)
                AND (
                    license_valid_days IS NULL
                    OR license_renew_before_days IS NULL
                    OR license_renew_before_days < license_valid_days
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_masa_lisensi_masuk_akal');
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_lisensi_berpasangan');

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'license_perpetual',
                'license_valid_days',
                'license_renew_before_days',
                'license_issued_perpetual',
            ]);
        });

        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_lisensi_berpasangan CHECK ((license_issued_at IS NULL) = (license_valid_until IS NULL))');
    }
};
