<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan pemasangan module per tenant.
 *
 * Tabel `apps` menyimpan katalog: produk apa yang dikenal platform. Tabel ini menyimpan
 * hal yang berbeda dan tidak boleh disimpulkan dari katalog maupun dari entitlement —
 * module apa yang benar-benar terpasang untuk tenant mana.
 *
 * Kunci utamanya gabungan `tenant_id` dan `module_id`: module dibeli per tenant, bukan
 * per legal entity. Sebuah grup dengan apotek dan klinik sebagai dua legal entity di
 * bawah satu tenant membeli modulnya sekali, lalu memakai legal entity untuk memisahkan
 * datanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_module_installations', function (Blueprint $table): void {
            $table->ulid('tenant_id');
            $table->string('module_id', 100);
            $table->string('version', 50);

            // Tiga status, bukan dua. `disabled` berarti module dimatikan sementara dan
            // menunya hilang; `uninstalled` berarti tenant berhenti memakainya. Keduanya
            // tidak menyentuh data, dan barisnya tetap ada supaya `seeded_at` bertahan.
            // Menghapus baris ini saat pencabutan akan membuat data awal terisi ulang saat
            // tenant berlangganan lagi, di atas data lama yang tidak pernah dihapus.
            $table->string('status', 20)->default('installed');

            // Yang mencegah data awal terisi dua kali saat tenant berlangganan ulang.
            // Sudah diuji sebelum tabel ini ditulis: tanpa kolom ini, master bawaan
            // menjadi dobel setiap kali module diaktifkan kembali.
            $table->timestampTz('seeded_at')->nullable();

            $table->timestampTz('installed_at');
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('uninstalled_at')->nullable();
            $table->timestamps();

            $table->primary(['tenant_id', 'module_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Status dijaga database, bukan hanya model. Baris ini ditulis perintah pemasangan,
        // perintah pencabutan, dan nanti alur pendaftaran tenant; satu di antaranya menulis
        // status yang salah eja sudah cukup membuat menu module hilang tanpa jejak.
        DB::statement(
            'ALTER TABLE core_module_installations ADD CONSTRAINT core_module_installations_status_check '.
            "CHECK (status IN ('installed', 'disabled', 'uninstalled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('core_module_installations');
    }
};
