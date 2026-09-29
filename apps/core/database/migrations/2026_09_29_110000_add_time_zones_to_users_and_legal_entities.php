<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zona waktu pengguna dan entitas legal (area 7 TODO analisa gap BC fase 1, K-10).
 *
 * Jam selalu diambil dari server dalam UTC; zona hanya menentukan tanggal dan jam yang dibaca pengguna,
 * termasuk "hari ini" yang menjadi bawaan tanggal kerja. Pengguna yang belum memilih zona (`users.timezone`
 * kosong) mengikuti zona entitas legal yang sedang aktif, seperti `getCompanyTimeZone` di F&O. Nama zona
 * IANA, 40 karakter, bawaan entitas legal `Asia/Jakarta` — bentuk yang sama dengan `sites.timezone`.
 *
 * `users` tinggal di database pusat dan `legal_entities` di database tenant; migration Core berjalan di
 * keduanya, jadi kedua kolom hadir di keduanya dan hanya salinan yang dipakai yang terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone', 40)->nullable();
        });

        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->string('timezone', 40)->default('Asia/Jakarta');
        });
    }

    public function down(): void
    {
        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
