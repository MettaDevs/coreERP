<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan aset tetap: satu baris per tenant, padanan tabel `FA Setup` (5603) Business Central dan
 * *Fixed assets parameters* F&O.
 *
 * Hanya field yang benar-benar dibaca kode yang dibuat. Field BC lain yang sengaja belum ada dicatat di
 * docs/apps/management-aset/master/pengaturan/. Setiap field menjadi kolom nullable sendiri, bukan
 * baris kunci-nilai: fitur yang butuh pengaturan baru (misalnya asuransi) menambah kolomnya lewat
 * migration sendiri, dan nilainya tetap bertipe dan bisa dijaga foreign key.
 *
 * Barisnya lahir saat pengaturan pertama kali disimpan, bukan lewat seeder: tenant yang belum pernah
 * menyimpan cukup membaca nilai kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_pengaturan_aset_tetap', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            // Default Depr. Book BC: buku yang angkanya dipakai saat satu aset perlu satu nilai.
            $table->ulid('buku_penyusutan_bawaan_id')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'buku_penyusutan_bawaan_id'], 'aset_pengaturan_buku_bawaan_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_buku_penyusutan')->restrictOnDelete();
        });

        // Satu baris aktif per tenant. Parsial, seperti setiap indeks unik di tabel yang mengarsipkan.
        DB::statement(
            'CREATE UNIQUE INDEX aset_pengaturan_aset_tetap_tenant_unique '.
            'ON aset_pengaturan_aset_tetap (tenant_id) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('aset_pengaturan_aset_tetap');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_pengaturan_aset_tetap');
    }
};
