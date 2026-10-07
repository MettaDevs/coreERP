<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mendaftarkan pilihan scope tanpa menambah hak pada token yang sudah terbit.
        DB::table('integration_scopes')->insertOrIgnore([
            'code' => 'legal-entities.read',
            'name' => 'Membaca entitas legal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Rollback image tetap mempertahankan katalog yang dirujuk token integrasi.
    }
};
