<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lokasi aset mengikuti functional location Dynamics 365 (docs/todo/master-bersama, "Lokasi aset
 * mengikuti functional location D365").
 *
 * `alamat_id` menunjuk tempat beralamat pos di buku alamat Core (`locations`). Lokasi yang kosong
 * mewarisi alamat lokasi induk terdekat yang punya alamat — persis Address pada functional location.
 * Tanpa foreign key: tabelnya milik Core, dan pada database tenant sendiri Core yang menjaganya.
 *
 * `departemen_bawaan_id` adalah unit kerja Core yang mengisi unit penanggung jawab aset saat aset
 * diterima atau dimutasi ke lokasi ini, dan mewarisi dengan aturan yang sama. Kolom ini berbeda dari
 * `org_unit_id` (dimensi keuangan, `Services/LocationDimension`): yang satu menjawab siapa yang
 * bertanggung jawab, yang lain siapa yang menanggung biaya.
 *
 * Keduanya nullable, jadi kode rilis sebelumnya tetap berjalan di atas skema ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_m_lokasi_aset', function (Blueprint $table): void {
            $table->ulid('alamat_id')->nullable();
            $table->ulid('departemen_bawaan_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aset_m_lokasi_aset', function (Blueprint $table): void {
            $table->dropColumn(['alamat_id', 'departemen_bawaan_id']);
        });
    }
};
