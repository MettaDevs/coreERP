<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat mesin milik klien, terpisah dari alamat aplikasinya.
 *
 * `sites.address` sudah ada, dan isinya alamat yang dibuka pengguna klinik: `https://erp.klinik.id`.
 * Yang dibutuhkan operator berbeda. Saat server klien bermasalah ia harus tahu **mesin mana** yang
 * didatangi, lewat SSH atau lewat penyedia VPS-nya, dan jawaban itu selama ini hanya ada di catatan
 * pribadi orang yang memasangnya. Alamat aplikasi tidak menjawabnya: ia dapat menunjuk proxy di depan
 * mesinnya, atau belum ada sama sekali pada server yang baru dipasang.
 *
 * Dua kolom, dua asal:
 *
 * - `server_address` — IP atau nama host yang dicatat operator. Boleh kosong, karena pemasangan tidak
 *   menunggunya.
 * - `last_seen_ip` — alamat asal laporan agen terakhir, dicatat admin.erp sendiri. Isinya tidak selalu
 *   sama dengan `server_address`: server di belakang NAT klinik melapor dari IP gerbangnya. Karena itu
 *   ia tidak pernah menimpa isian operator, hanya ditampilkan di sampingnya.
 *
 * ## Bentuk yang ditegakkan
 *
 * Tanpa skema, jalur, port, atau spasi, dan huruf kecil. `https://103.122.2.72/` yang tertempel dari
 * peramban adalah alamat aplikasi, bukan alamat mesin, dan menyimpannya di sini membuat dua kolom yang
 * maksudnya berbeda berisi hal yang sama. Aturan lengkapnya — IPv4, IPv6, atau nama host yang sah —
 * diperiksa konsol (`ServerAddress`). CHECK di sini hanya menahan bentuk yang pasti salah dari penulis
 * lain.
 *
 * Nullable dan tanpa nilai bawaan, jadi rilis sebelumnya yang tidak mengenal kedua kolom ini tetap
 * menulis tabel yang sama tanpa ditolak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('server_address', 255)->nullable();
            $table->string('last_seen_ip', 45)->nullable();
        });

        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_alamat_server_polos CHECK (server_address IS NULL OR (server_address <> '' AND server_address = lower(server_address) AND server_address !~ '[[:space:]/@]'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_alamat_server_polos');

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['server_address', 'last_seen_ip']);
        });
    }
};
