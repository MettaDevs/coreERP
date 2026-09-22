<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klien integrasi: sistem di luar CoreERP yang membaca feed posting finance dan data rujukannya.
 *
 * Kredensial app yang sudah ada (`app_service_credentials`) tidak cocok untuk ini. Ia mensyaratkan
 * hak dan pemasangan sebuah module, sedangkan aplikasi finance pelanggan bukan module CoreERP. Klien
 * integrasi menyatakan dirinya sendiri: milik satu tenant, dengan cakupan sempit, dan satu mode
 * pengiriman — `pull` (pembaca menarik) atau `push` (CoreERP mengirim ke URL pembaca).
 *
 * Token tidak pernah disimpan, hanya digest-nya; ia ditampilkan sekali saat terbit. Rahasia
 * penanda tangan `push` disimpan terenkripsi karena CoreERP sendiri harus bisa memakainya.
 *
 * `tenant_id` tanpa foreign key ke `tenants` (sisi pusat, `FkMenyeberangBatasTest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_clients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name', 120);
            $table->string('token_digest', 64);
            $table->json('scopes');
            $table->json('allowed_ips')->nullable();
            $table->json('posting_type_prefixes')->nullable();
            $table->string('delivery_mode', 10)->default('pull');
            $table->string('push_url', 500)->nullable();
            $table->text('signing_secret')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by_user_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement(
            "alter table integration_clients add constraint integration_clients_mode_check
             check (delivery_mode in ('pull', 'push'))",
        );
        DB::statement(
            "alter table integration_clients add constraint integration_clients_status_check
             check (status in ('active', 'revoked'))",
        );
        // Mode `push` tanpa tujuan atau tanpa rahasia tanda tangan adalah klien yang tidak bisa
        // bekerja, dan tujuan tanpa TLS berarti jurnal keuangan terkirim terbuka di jaringan.
        DB::statement(
            "alter table integration_clients add constraint integration_clients_push_check
             check (delivery_mode <> 'push' or (push_url like 'https://%' and signing_secret is not null))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_clients');
    }
};
