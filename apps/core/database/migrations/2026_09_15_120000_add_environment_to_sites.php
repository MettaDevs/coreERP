<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sebuah situs adalah tempat satu lingkungan berjalan, dan kini ia menyebut lingkungan yang mana.
 *
 * Sebelumnya situs hanya menunjuk tenant, dan hubungannya dengan produksi tenant itu tersirat dari
 * kebiasaan: satu klien, satu server. Pemasangan satu perintah menjadikannya eksplisit — situs lahir
 * dari halaman lingkungan produksi yang berjalan di server klien, dan status pemasangannya dibaca di
 * halaman yang sama. Tanpa kolom ini keduanya disambung lewat tebakan "situs pertama milik tenant
 * ini", dan tebakan itu salah pada hari tenant yang sama punya situs kedua.
 *
 * Kosong tetap sah. Situs yang didaftarkan sebelum hari ini tidak menyebut lingkungan apa pun, dan
 * mengisinya dengan tebakan adalah kesalahan yang sama dalam bentuk migration.
 *
 * ## Satu situs per lingkungan
 *
 * Dua situs yang menunjuk lingkungan yang sama berarti dua server yang sama-sama mengaku menjalankan
 * produksi yang sama — dua agen yang mengambil operasi pasang yang sama, dua database yang sama-sama
 * dianggap asli. Ditolak di sini, dengan partial unique index, supaya dua klik "Siapkan server klien"
 * yang berlomba tidak melahirkan keduanya.
 *
 * ## Tenant situs dan tenant lingkungannya sama, dan itu juga ditegakkan di sini
 *
 * Foreign key-nya gabungan: `(environment_id, tenant_id)` menunjuk `(id, tenant_id)` milik
 * `environments`. Situs milik tenant A yang menunjuk produksi tenant B akan membuat agen di server A
 * menerima perintah pasang berisi nama, email owner, dan app milik B. Kesalahan itu hanya perlu satu
 * id yang tertukar di satu jalur yang lupa memeriksanya, jadi ia ditolak PostgreSQL, bukan diharapkan
 * tidak terjadi. Kuncinya membutuhkan unique `(id, tenant_id)` di `environments`; `id` sendiri sudah
 * unik, jadi pasangan itu tidak mengubah apa pun selain memberi foreign key ini sesuatu untuk ditunjuk.
 *
 * Yang **tidak** ditegakkan di sini: bahwa lingkungan yang ditunjuk berjalan di server klien. CHECK
 * tidak dapat membaca tabel lain, dan menegakkannya lintas tabel menuntut trigger di dua tabel
 * sekaligus. Penjaganya konsol, yang membuat situs hanya dari lingkungan `client_server`.
 *
 * `restrictOnDelete`, sama seperti setiap rujukan lain ke registry ini: baris `environments` tidak
 * pernah dihapus — penghapusan permanen hanya membuang databasenya — jadi rujukan yang ikut hilang
 * bersama barisnya tidak punya keadaan sah untuk dijaga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'environments_id_tenant_unik');
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->ulid('environment_id')->nullable();

            $table->foreign(['environment_id', 'tenant_id'], 'sites_lingkungan_milik_tenant_yang_sama')
                ->references(['id', 'tenant_id'])
                ->on('environments')
                ->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX sites_satu_per_lingkungan ON sites (environment_id) WHERE environment_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sites_satu_per_lingkungan');

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropForeign('sites_lingkungan_milik_tenant_yang_sama');
            $table->dropColumn('environment_id');
        });

        Schema::table('environments', function (Blueprint $table): void {
            $table->dropUnique('environments_id_tenant_unik');
        });
    }
};
