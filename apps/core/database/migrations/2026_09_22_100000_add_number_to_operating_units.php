<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor operating unit: kode stabil yang dipakai sebagai nilai dimensi keuangan.
 *
 * `organizations.code` dihapus pada 24 Juli 2026, dan sejak itu tidak ada satu pun kode yang bisa
 * dikirim ke aplikasi finance untuk menyebut klinik atau poli. Padanannya di Dynamics 365 F&O adalah
 * *operating unit number*: nilai dimensi `BusinessUnit` dan `Department` adalah nomor itu, bukan
 * nama. Nama boleh berganti tanpa mengubah jurnal lama; nomor yang terkirim bersama posting disimpan
 * sebagai salinan di posting itu sendiri.
 *
 * `tenant_id` disalin ke sini, mengikuti pola `legal_entities` (2026_07_24_010000), karena keunikan
 * nomor berlaku per tenant sedangkan tenant hanya tercatat di `organizations`. PostgreSQL tidak dapat
 * membuat indeks unik yang menyeberang tabel.
 *
 * Berbeda dengan `legal_entities`, kolomnya **tanpa** foreign key ke `tenants`. Tabel itu milik sisi
 * pusat yang kelak pindah ke database sendiri, dan setiap constraint yang menyeberang ke sana harus
 * dibongkar hari itu (`FkMenyeberangBatasTest`). Baris operating unit sudah terhapus bersama
 * organisasinya lewat `organization_id`, jadi yang hilang hanya pemeriksaan yang toh akan hilang.
 *
 * Kedua kolom boleh kosong. Kode rilis sebelumnya membuat operating unit tanpa mengenal keduanya,
 * dan indeks unik parsial tidak pernah menolak baris yang nomornya kosong. Kode rilis ini mengisi
 * `tenant_id` setiap kali menyimpan nomor, jadi baris yang lahir di rilis sebelumnya ikut lengkap
 * begitu nomornya diisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            $table->ulid('tenant_id')->nullable()->after('organization_id');
            $table->string('number', 30)->nullable()->after('type');
        });

        DB::statement(
            'update operating_units
             set tenant_id = organizations.tenant_id
             from organizations
             where organizations.id = operating_units.organization_id
               and operating_units.tenant_id is null',
        );

        DB::statement(
            'create unique index operating_units_tenant_number_unique
             on operating_units (tenant_id, number)
             where number is not null',
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists operating_units_tenant_number_unique');

        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropColumn(['tenant_id', 'number']);
        });
    }
};
