<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar akun referensi: akun milik aplikasi finance pelanggan, bukan buku besar CoreERP (K-05).
 *
 * CoreERP belum punya modul Finance, dan tidak dibangun demi integrasi ini. Yang dibutuhkan hanya
 * daftar akun untuk dipilih di pemetaan posting, dengan satu sifat penting: pemetaan menunjuk
 * `external_id` (di old-finance: `Akun_ID`) yang tidak pernah berubah, sehingga nomor dan nama akun
 * boleh diganti di sisi finance tanpa memutus pemetaan. Kelak tabel ini diambil alih modul Finance
 * sebagai master COA tanpa mengubah pemakainya.
 *
 * `legal_entity_id` kosong berarti akun berlaku untuk semua entitas legal tenant. Karena PostgreSQL
 * menganggap dua NULL berbeda, keunikannya dijaga dua indeks parsial, bukan satu indeks gabungan.
 *
 * `tenant_id` tanpa foreign key ke `tenants` (sisi pusat, `FkMenyeberangBatasTest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_reference_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('legal_entity_id')->nullable();
            $table->string('external_id', 64);
            $table->string('code', 50);
            $table->string('name', 200);
            $table->string('type', 20);
            $table->boolean('active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'legal_entity_id', 'active']);
            $table->index(['tenant_id', 'code']);
            $table->foreign(['tenant_id', 'legal_entity_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->cascadeOnDelete();
        });

        DB::statement(
            'create unique index finance_reference_accounts_tenant_external_unique
             on finance_reference_accounts (tenant_id, external_id)
             where legal_entity_id is null',
        );
        DB::statement(
            'create unique index finance_reference_accounts_entity_external_unique
             on finance_reference_accounts (tenant_id, legal_entity_id, external_id)
             where legal_entity_id is not null',
        );
        DB::statement(
            "alter table finance_reference_accounts add constraint finance_reference_accounts_type_check
             check (type in ('balance_sheet', 'profit_loss'))",
        );

        // Riwayat impor: siapa, kapan, berkas apa, dan hasilnya. Baris yang ditolak disimpan
        // bersama alasannya supaya impor yang gagal dapat ditelusuri tanpa berkas aslinya.
        Schema::create('finance_reference_account_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('legal_entity_id')->nullable();
            $table->string('file_name', 255);
            $table->string('imported_by_user_id', 64)->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->json('rejected_rows')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement(
            "alter table finance_reference_account_imports add constraint finance_reference_account_imports_status_check
             check (status in ('applied', 'rejected'))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reference_account_imports');
        Schema::dropIfExists('finance_reference_accounts');
    }
};
