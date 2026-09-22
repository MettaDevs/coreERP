<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feed posting finance (area 6, K-01..K-23).
 *
 * Empat tabel, masing-masing satu tanggung jawab:
 *
 * - `finance_postings`: satu posting = satu dokumen sumber dengan jurnal seimbang. `payload` adalah
 *   bentuk kontrak persis seperti yang disajikan ke pembaca, dan `input` adalah permintaan asli
 *   module — disimpan supaya posting yang tertahan dapat dibentuk ulang dari sumber yang sama
 *   setelah pemetaannya diperbaiki, dengan `posting_id` yang sama.
 * - `finance_posting_lines`: baris jurnal sebagai kolom, di samping payload JSON, supaya laporan per
 *   business unit dan department tidak perlu membongkar JSON (padanan *global dimension* BC).
 * - `finance_posting_deliveries`: jejak pengiriman mode `push` per klien — percobaan, jeda, dan
 *   kegagalan. Mode `pull` tidak butuh baris di sini; ia dicatat di `served_count`.
 * - `finance_posting_events`: riwayat perubahan status, untuk layar pantau.
 *
 * `tenant_id` tanpa foreign key ke `tenants` (sisi pusat). Entitas legal ditunjuk lewat foreign key
 * gabungan dengan `tenant_id`, jadi database menolak entitas legal tenant lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_postings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('legal_entity_id');
            $table->string('posting_id', 120);
            $table->string('posting_type', 80);
            $table->unsignedSmallInteger('contract_version')->default(1);
            $table->string('source_module', 80);
            $table->string('source_type', 80);
            $table->string('source_number', 80)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->char('currency_code', 3);
            $table->unsignedSmallInteger('currency_decimals');
            $table->date('posting_date');
            $table->date('document_date');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at');
            $table->string('settlement_mode', 20)->nullable();
            $table->string('status', 20);
            $table->string('manual_reason', 30)->nullable();
            $table->json('hold_reasons')->nullable();
            $table->ulid('vendor_id')->nullable();
            $table->string('reverses_posting_id', 120)->nullable();
            $table->string('adjusts_posting_id', 120)->nullable();
            $table->decimal('total_debit', 24, 6);
            $table->decimal('total_credit', 24, 6);
            $table->jsonb('payload');
            $table->jsonb('input');
            $table->char('input_hash', 64);
            $table->string('external_reference', 120)->nullable();
            $table->string('reason_code', 40)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->ulid('acknowledged_by_client_id')->nullable();
            $table->unsignedInteger('served_count')->default(0);
            $table->timestamp('last_served_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'posting_id']);
            // Urutan tarikan: posting yang siap, menurut tanggal akuntansi lalu jam terbit.
            $table->index(['tenant_id', 'status', 'posting_date', 'published_at']);
            $table->index(['tenant_id', 'legal_entity_id', 'posting_date']);
            $table->foreign(['tenant_id', 'legal_entity_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            alter table finance_postings
                add constraint finance_postings_status_check
                    check (status in ('held', 'pending', 'posted', 'rejected', 'manual')),
                add constraint finance_postings_manual_reason_check
                    check ((status = 'manual') = (manual_reason is not null)
                        and (manual_reason is null or manual_reason in ('before_cutover', 'feed_disabled', 'user'))),
                add constraint finance_postings_balanced_check
                    check (total_debit = total_credit and total_debit > 0),
                add constraint finance_postings_posted_reference_check
                    check (status <> 'posted' or external_reference is not null),
                add constraint finance_postings_rejected_reason_check
                    check (status <> 'rejected' or reason_code is not null)
            SQL);

        Schema::create('finance_posting_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('finance_posting_id')->constrained('finance_postings')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->ulid('account_id')->nullable();
            $table->string('account_external_id', 64)->nullable();
            $table->string('account_code', 50)->nullable();
            $table->decimal('debit', 24, 6)->default(0);
            $table->decimal('credit', 24, 6)->default(0);
            $table->string('description', 255)->nullable();
            $table->ulid('org_unit_id')->nullable();
            $table->string('business_unit_code', 30)->nullable();
            $table->string('department_code', 30)->nullable();
            $table->timestamps();

            $table->unique(['finance_posting_id', 'line_no']);
            $table->index(['tenant_id', 'business_unit_code']);
            $table->index(['tenant_id', 'department_code']);
        });

        DB::statement(<<<'SQL'
            alter table finance_posting_lines
                add constraint finance_posting_lines_one_side_check
                    check (debit >= 0 and credit >= 0 and (debit = 0) <> (credit = 0))
            SQL);

        Schema::create('finance_posting_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('finance_posting_id')->constrained('finance_postings')->cascadeOnDelete();
            $table->foreignUlid('integration_client_id')->constrained('integration_clients')->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['finance_posting_id', 'integration_client_id']);
            $table->index(['integration_client_id', 'status', 'next_attempt_at']);
        });

        DB::statement(<<<'SQL'
            alter table finance_posting_deliveries
                add constraint finance_posting_deliveries_status_check
                    check (status in ('retrying', 'delivered', 'failed'))
            SQL);

        Schema::create('finance_posting_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('finance_posting_id')->constrained('finance_postings')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->ulid('integration_client_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['finance_posting_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_posting_events');
        Schema::dropIfExists('finance_posting_deliveries');
        Schema::dropIfExists('finance_posting_lines');
        Schema::dropIfExists('finance_postings');
    }
};
