<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Di mana sebuah lingkungan berjalan: di server kami, atau di server milik klien.
 *
 * Sampai hari ini jawabannya selalu "di server kami", dan karena itu tidak pernah ditulis. Produksi
 * yang dipasang di server klien — lewat satu perintah pasang, dikelola dari admin.erp — mengubahnya:
 * baris `environments`-nya tetap ada di registry pusat, karena di sanalah tenant, situs, dan riwayat
 * operatornya menunjuk, tetapi **isinya tidak ada di sini**. Core di server kami tidak boleh
 * merutekannya, menyiapkan databasenya, menyalinnya, memperbaruinya, atau memasang module ke
 * dalamnya — setiap langkah itu akan berjalan di database pooled, di atas data tenant lain.
 *
 * Kolom ini yang membedakannya. Bawaannya `provider`, jadi setiap baris yang sudah ada dan setiap
 * jalur yang tidak menyebutnya tetap berperilaku persis seperti kemarin. Di server klien sendiri
 * baris produksinya juga `provider`: dari sudut Core yang berjalan di sana, ia memang yang
 * menjalankannya.
 *
 * ## Kenapa aturannya di constraint
 *
 * Server klien hanya menjalankan produksi — demo dan sandbox tetap di server kami — dan lingkungan
 * di server klien tidak pernah punya database di server kami. Keduanya dinyatakan sebagai satu
 * CHECK, bukan hanya di konsol. Penulis registry ini lebih dari satu: konsol operator, perintah
 * artisan Core, dan UPDATE tangan pada pukul dua pagi. `database_name` yang terisi pada lingkungan
 * server klien adalah persis keadaan yang membuat `environment:upgrade` menjalankan migration ke
 * sebuah database yang bukan milik siapa pun.
 *
 * ## CHECK gabungannya hanya di database pusat
 *
 * Aturannya sama dengan `2026_09_14_100000_add_satu_per_jenis_to_environments`, dan alasannya juga:
 * tabel `environments` ada di setiap database lingkungan karena migration dibagi, isinya tidak dibaca
 * siapa pun, dan `CopyEnvironment::disarmCopiedRegistry` sengaja menurunkan **setiap** baris di
 * salinan menjadi `sandbox`. Satu baris `client_server` di registry salinan akan membuat penurunan itu
 * ditolak constraint ini — pelucutan yang gagal karena registry yang tidak berwenang. Jadi di koneksi
 * `environment_*` CHECK gabungannya dilewati, dan `disarmCopiedRegistry` membuangnya lebih dulu bagi
 * database yang pernah dimigrasi lewat koneksi bernama lain.
 *
 * Kolom dan daftar nilainya tetap dipasang di mana-mana. Skema yang sama di setiap database adalah
 * dasar sidik skema, dan tidak ada jalur yang dirugikan oleh nilai yang dikenal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->string('hosting', 20)->default('provider');
        });

        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_hosting_dikenal CHECK (hosting IN ('provider', 'client_server'))");

        if ($this->onEnvironmentDatabase()) {
            return;
        }

        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_server_klien_hanya_produksi CHECK (hosting = 'provider' OR (kind = 'production' AND database_name IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE environments DROP CONSTRAINT IF EXISTS environments_server_klien_hanya_produksi');
        DB::statement('ALTER TABLE environments DROP CONSTRAINT IF EXISTS environments_hosting_dikenal');

        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('hosting');
        });
    }

    private function onEnvironmentDatabase(): bool
    {
        return str_starts_with(DB::connection()->getName() ?? '', 'environment_');
    }
};
