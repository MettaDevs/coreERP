<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contoh_a_m_barang', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->softDeletes();
            $table->timestamps();

            // Sengaja tidak memakai $table->unique(['tenant_id', 'kode']). Indeks unik
            // penuh ikut menghitung baris yang sudah diarsipkan, sehingga kode yang
            // dihapus tidak pernah bisa dipakai ulang. Lihat "Penghapusan lunak" pada
            // docs/dev/02-module-standard.md.
        });

        DB::statement(
            'CREATE UNIQUE INDEX contoh_a_m_barang_tenant_kode_unique '.
            'ON contoh_a_m_barang (tenant_id, kode) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contoh_a_m_barang_tenant_kode_unique');
        Schema::dropIfExists('contoh_a_m_barang');
    }
};
