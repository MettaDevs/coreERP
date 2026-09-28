<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kode kalender kerja unik per entitas legal, hanya di antara kalender yang belum diarsipkan.
 *
 * Sebelumnya keunikan hanya dijaga validasi, dan validasinya membandingkan kode seperti yang
 * diketik sementara yang tersimpan adalah huruf besarnya: `abc` lolos di samping `ABC`, dan
 * tenant berakhir dengan dua kalender `ABC`. Indeks ini penjaga terakhirnya; validasi tetap
 * yang memberi pesan yang bisa dibaca.
 *
 * Cakupannya entitas legal, bukan tenant, karena daftar kalender memang ditampilkan per entitas
 * legal — sama seperti kalender di Dynamics 365 yang milik satu perusahaan. Dengan cakupan
 * tenant, pengguna di entitas B ditolak memakai kode yang dipakai entitas A tanpa bisa melihat
 * kalender mana yang memakainya.
 *
 * Parsial karena penghapusan di repo ini selalu lunak: kode kalender yang sudah diarsipkan boleh
 * dipakai lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX working_time_calendars_code_unique '.
            'ON working_time_calendars (tenant_id, legal_entity_id, code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS working_time_calendars_code_unique');
    }
};
