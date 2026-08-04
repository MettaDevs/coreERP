<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['m_kondisi_aset', 'm_pabrikan_aset'] as $masterTable) {
            Schema::table($masterTable, function (Blueprint $table): void {
                $table->unique(['tenant_id', 'id']);
            });
        }

        Schema::create('m_lokasi_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('parent_id')->nullable();
            $table->string('nama', 150);
            $table->boolean('aktif')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'kode']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('m_lokasi_aset')->restrictOnDelete();
        });

        Schema::create('m_profil_penyusutan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->string('method', 40);
            $table->string('frequency', 20);
            $table->string('year_basis', 20);
            $table->string('convention', 40)->nullable();
            $table->unsignedInteger('useful_life_periods')->nullable();
            $table->decimal('rate_percent', 9, 4)->nullable();
            $table->json('manual_schedule')->nullable();
            $table->boolean('aktif')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'kode']);
            $table->unique(['tenant_id', 'creation_key']);
        });

        Schema::create('t_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            $table->ulid('jenis_aset_id');
            $table->ulid('kondisi_aset_id')->nullable();
            $table->ulid('pabrikan_aset_id')->nullable();
            $table->ulid('asset_location_id')->nullable();
            $table->string('serial_number', 150)->nullable();
            $table->string('model_number', 150)->nullable();
            $table->date('acquired_on');
            $table->date('placed_in_service_on')->nullable();
            $table->decimal('acquisition_value', 18, 2);
            $table->string('currency_code', 3);
            $table->string('lifecycle_state', 30)->default('received');
            $table->text('keterangan')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'kode']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->foreign(['tenant_id', 'jenis_aset_id'])->references(['tenant_id', 'id'])->on('m_jenis_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'kondisi_aset_id'])->references(['tenant_id', 'id'])->on('m_kondisi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pabrikan_aset_id'])->references(['tenant_id', 'id'])->on('m_pabrikan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asset_location_id'])->references(['tenant_id', 'id'])->on('m_lokasi_aset')->restrictOnDelete();
        });

        Schema::create('t_aset_penempatan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('asset_id');
            $table->ulid('receiving_org_unit_id')->nullable();
            $table->ulid('usage_org_unit_id')->nullable();
            $table->string('received_by_user_id', 64)->nullable();
            $table->string('custodian_user_id', 64)->nullable();
            $table->ulid('asset_location_id')->nullable();
            $table->date('effective_on');
            $table->string('reason', 250)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'asset_id', 'effective_on']);
            $table->foreign(['tenant_id', 'asset_id'])->references(['tenant_id', 'id'])->on('t_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asset_location_id'])->references(['tenant_id', 'id'])->on('m_lokasi_aset')->restrictOnDelete();
        });

        Schema::create('t_buku_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('asset_id');
            $table->ulid('depreciation_profile_id');
            $table->string('book_code', 50);
            $table->decimal('acquisition_value', 18, 2);
            $table->decimal('residual_value', 18, 2)->default(0);
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('net_book_value', 18, 2);
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'asset_id', 'book_code']);
            $table->foreign(['tenant_id', 'asset_id'])->references(['tenant_id', 'id'])->on('t_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'depreciation_profile_id'])->references(['tenant_id', 'id'])->on('m_profil_penyusutan')->restrictOnDelete();
        });

        Schema::create('t_penyusutan_periode', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('asset_book_id');
            $table->ulid('legal_entity_id')->index();
            $table->ulid('usage_org_unit_id')->nullable();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->decimal('amount', 18, 2);
            $table->string('status', 30)->default('proposed');
            $table->ulid('reverses_period_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'asset_book_id', 'period_ends_on', 'reverses_period_id']);
            $table->foreign(['tenant_id', 'asset_book_id'])->references(['tenant_id', 'id'])->on('t_buku_aset')->restrictOnDelete();
        });

        Schema::create('t_export_penyusutan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('posting_id', 80);
            $table->ulid('depreciation_period_id');
            $table->json('payload');
            $table->timestamp('finalized_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('external_reference', 160)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'posting_id']);
            $table->unique(['tenant_id', 'depreciation_period_id']);
            $table->foreign(['tenant_id', 'depreciation_period_id'])->references(['tenant_id', 'id'])->on('t_penyusutan_periode')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['t_export_penyusutan', 't_penyusutan_periode', 't_buku_aset', 't_aset_penempatan', 't_aset', 'm_profil_penyusutan', 'm_lokasi_aset'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
