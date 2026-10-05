<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'analytics.read' => 'Membaca publikasi analitik',
            'analytics.embed' => 'Mencetak token embed analitik',
        ] as $code => $name) {
            DB::table('integration_scopes')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Scope dapat sudah dipakai token; rollback tidak menghapus katalog integrasi.
    }
};
