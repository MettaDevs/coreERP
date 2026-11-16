<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logo aplikasi pengganti milik satu tenant: yang tampil di header, di tab peramban, dan pada
 * pratinjau link. Satu baris per tenant; logo yang dicabut mengosongkan kolomnya, barisnya tetap.
 *
 * Tabel baru, jadi aman untuk rilis sebelumnya: kode lama tidak pernah membacanya.
 * Migration Core juga dijalankan admin.erp tanpa autoload kelas Core, karena itu trigger
 * jejaknya ditulis sebagai SQL langsung. `tenant_id` tanpa foreign key ke `tenants` (tabel sisi pusat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brandings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->string('logo_path', 255)->nullable();
            $table->string('logo_mime', 50)->nullable();
            // SHA-256 isi berkas; potongannya menjadi versi pada alamat logo supaya peramban
            // mengambil ulang hanya ketika gambarnya memang berganti.
            $table->string('logo_hash', 64)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        DB::statement('CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON tenant_brandings FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()');
        DB::statement('CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON tenant_brandings FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()');
        DB::statement('CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON tenant_brandings FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()');
    }

    public function down(): void {}
};
