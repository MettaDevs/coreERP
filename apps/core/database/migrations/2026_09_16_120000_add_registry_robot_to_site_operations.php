<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Robot registry yang diterbitkan untuk satu operasi `install` atau `upgrade` (CP-02 di
 * `docs/todo/registry-harbor`).
 *
 * Satu robot per operasi, bukan per situs: rahasia yang bocor dari server klien mati bersama operasinya.
 * Id robot di Harbor dicatat di baris operasinya supaya konsol dapat menghapusnya begitu operasi itu
 * ditutup — dengan cara apa pun ia ditutup — dan supaya pemanggilan ulang selama operasi yang sama
 * mengganti robot yang ada alih-alih menumpuknya.
 *
 * Kolomnya sengaja tidak dikosongkan oleh penutup operasi. Yang mengosongkannya hanya penghapusan robot
 * yang berhasil: baris tertutup yang masih membawa id robot adalah robot yang belum terhapus, dan itulah
 * yang dicari penyapunya setiap kali agen menyambung.
 *
 * Nama robot di Harbor ikut dicatat karena ia yang ditulis di log dan audit Harbor; id-nya tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('registry_robot_id')->nullable();
            $table->string('registry_robot_name', 255)->nullable();
        });

        // Robot hanya lahir untuk operasi yang menarik image, dan id serta namanya selalu berpasangan.
        DB::statement("ALTER TABLE site_operations ADD CONSTRAINT site_operations_robot_hanya_penarik CHECK (registry_robot_id IS NULL OR operation IN ('install', 'upgrade'))");
        DB::statement('ALTER TABLE site_operations ADD CONSTRAINT site_operations_robot_berpasangan CHECK ((registry_robot_id IS NULL) = (registry_robot_name IS NULL))');
        DB::statement('CREATE INDEX site_operations_robot_tersisa ON site_operations (site_id) WHERE registry_robot_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS site_operations_robot_tersisa');
        DB::statement('ALTER TABLE site_operations DROP CONSTRAINT IF EXISTS site_operations_robot_berpasangan');
        DB::statement('ALTER TABLE site_operations DROP CONSTRAINT IF EXISTS site_operations_robot_hanya_penarik');

        Schema::table('site_operations', function (Blueprint $table): void {
            $table->dropColumn(['registry_robot_id', 'registry_robot_name']);
        });
    }
};
