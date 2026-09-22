<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setelan feed posting finance per entitas legal, dan presisi uang per mata uang.
 *
 * Tiga tabel baru, jadi aman untuk rilis sebelumnya: kode lama tidak pernah membacanya.
 *
 * - `finance_posting_settings`: feed aktif atau tidak, dan tanggal cutover. Posting bertanggal
 *   sebelum cutover tidak dikirim, supaya riwayat yang sudah dijurnal manual tidak terkirim ulang.
 * - `finance_settlement_modes`: kebijakan jurnal perolehan dengan tanggal berlaku. Riwayatnya
 *   disimpan, bukan satu kolom yang ditimpa, karena koreksi atas perolehan lama harus mewarisi
 *   mode yang berlaku saat perolehan itu, bukan mode hari ini.
 * - `currency_precisions`: jumlah desimal untuk nilai dan untuk harga satuan, padanan *Amount
 *   Rounding Precision* dan *Unit-Amount Rounding Precision* pada Currency Card Business Central.
 *
 * `tenant_id` disimpan tanpa foreign key ke `tenants` (tabel sisi pusat, `FkMenyeberangBatasTest`).
 * Entitas legal ditunjuk lewat foreign key gabungan `(tenant_id, legal_entity_id)` ke
 * `organizations(tenant_id, id)`, jadi database sendiri yang menolak entitas milik tenant lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_posting_settings', function (Blueprint $table): void {
            $table->ulid('legal_entity_id')->primary();
            $table->ulid('tenant_id');
            $table->boolean('enabled')->default(false);
            $table->date('cutover_date')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->foreign(['tenant_id', 'legal_entity_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->cascadeOnDelete();
        });

        // Feed yang aktif tanpa cutover akan mengirim seluruh riwayat, termasuk yang sudah
        // dijurnal manual. Database yang menolaknya, bukan hanya layar.
        DB::statement(
            'alter table finance_posting_settings add constraint finance_posting_settings_enabled_needs_cutover
             check (not enabled or cutover_date is not null)',
        );

        Schema::create('finance_settlement_modes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('legal_entity_id');
            $table->string('mode', 20);
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['legal_entity_id', 'effective_from']);
            $table->index(['tenant_id', 'legal_entity_id']);
            $table->foreign(['tenant_id', 'legal_entity_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->cascadeOnDelete();
        });

        DB::statement(
            "alter table finance_settlement_modes add constraint finance_settlement_modes_mode_check
             check (mode in ('direct_payable', 'clearing'))",
        );

        Schema::create('currency_precisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->char('currency_code', 3);
            $table->unsignedSmallInteger('amount_decimals');
            $table->unsignedSmallInteger('unit_amount_decimals');
            $table->timestamps();

            $table->unique(['tenant_id', 'currency_code']);
        });

        DB::statement(
            'alter table currency_precisions add constraint currency_precisions_range_check
             check (amount_decimals between 0 and 4
                and unit_amount_decimals between 0 and 6
                and unit_amount_decimals >= amount_decimals)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_precisions');
        Schema::dropIfExists('finance_settlement_modes');
        Schema::dropIfExists('finance_posting_settings');
    }
};
