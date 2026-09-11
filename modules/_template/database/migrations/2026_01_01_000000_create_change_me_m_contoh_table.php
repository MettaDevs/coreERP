<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Cap waktu pada nama berkas ini adalah penanda; `module:make` menggantinya dengan waktu
 * pembuatan module. Migration module berjalan setelah migration Core, jadi cap waktu yang
 * lebih tua daripada Core pun tidak mengubah urutannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_me_m_contoh', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Setiap tabel module membawa `tenant_id`. Tidak ada lagi database terpisah yang
            // menahan kebocoran; yang menahannya trait `MilikTenant` pada modelnya, dan trait
            // itu tidak punya apa-apa untuk disaring bila kolom ini tidak ada.
            $table->ulid('tenant_id')->index();
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->softDeletes();
            $table->timestamps();

            // Sengaja tidak memakai $table->unique(['tenant_id', 'kode']). Indeks unik penuh
            // ikut menghitung baris yang sudah diarsipkan, sehingga kode yang dihapus tidak
            // pernah bisa dipakai ulang. Lihat "Penghapusan lunak" pada
            // docs/dev/02-module-standard.md.
        });

        DB::statement(
            'CREATE UNIQUE INDEX change_me_m_contoh_tenant_kode_unique '.
            'ON change_me_m_contoh (tenant_id, kode) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS change_me_m_contoh_tenant_kode_unique');
        Schema::dropIfExists('change_me_m_contoh');
    }
};
