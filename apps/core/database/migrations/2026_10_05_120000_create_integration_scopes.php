<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_scopes')) {
            Schema::create('integration_scopes', function (Blueprint $table): void {
                $table->string('code', 120)->primary();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Scope yang sudah dilayani tetap memakai kode yang sama. Domain baru mendaftar lewat migration.
        foreach ([
            'finance-postings.read' => 'Membaca posting finance',
            'finance-postings.ack' => 'Mengirim ack posting (dibukukan atau ditolak)',
            'vendors.read' => 'Membaca vendor',
            'operating-units.read' => 'Membaca operating unit dan nomornya',
        ] as $code => $name) {
            DB::table('integration_scopes')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Rollback image tidak menghapus katalog yang dirujuk token integrasi.
    }
};
