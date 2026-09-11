<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laporan yang dideklarasikan manifest app (`reports:`). Core mengenal katalognya
        // supaya halaman Layout laporan dan tombol cetak dapat dirender tanpa memanggil
        // app; dataset, placeholder, dan berkas layout bawaan tetap diminta ke app.
        Schema::create('app_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('app_id', 80);
            $table->string('code', 160)->unique();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            // Permission bisnis yang wajib dimiliki untuk menjalankan laporan; ditegakkan
            // Core saat permintaan dibuat dan oleh app saat dataset diminta.
            $table->string('permission', 160);
            $table->json('parameters');
            $table->json('builtin_layouts');
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('apps')->restrictOnDelete();
        });

        // Layout unggahan tenant, padanan user-defined layout Business Central. Layout
        // bawaan tidak ada di sini: ia milik release app dan dirujuk `bawaan:<kunci>`.
        Schema::create('report_layouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            // Kosong berarti seluruh legal entity tenant ("Available in All Companies").
            $table->ulid('legal_entity_id')->nullable();
            $table->string('report_code', 160);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('format', 10);
            $table->string('file_path', 255);
            $table->unsignedInteger('file_size');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'report_code']);
        });

        // Layout default per laporan per lingkup, padanan Report Selections. `scope_key`
        // ada karena PostgreSQL menganggap dua NULL berbeda pada unique index.
        Schema::create('report_layout_defaults', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('legal_entity_id')->nullable();
            $table->string('scope_key', 40);
            $table->string('report_code', 160);
            $table->string('layout_ref', 60);
            $table->timestamps();

            $table->unique(['tenant_id', 'report_code', 'scope_key']);
        });

        // Permintaan ekspor yang dikerjakan worker Core. Konteks organisasi dibekukan
        // di sini; hak akses tidak, karena token ke app diterbitkan ulang dari membership
        // saat job berjalan sehingga pencabutan hak langsung berlaku.
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('membership_id');
            $table->unsignedBigInteger('user_id');
            $table->ulid('legal_entity_id')->nullable();
            $table->ulid('org_unit_id')->nullable();
            $table->string('app_id', 80);
            $table->string('report_code', 160);
            $table->string('report_name', 160);
            $table->string('layout_ref', 60);
            $table->string('layout_name', 120);
            $table->string('format', 10);
            $table->json('parameters');
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('report_layout_defaults');
        Schema::dropIfExists('report_layouts');
        Schema::dropIfExists('app_reports');
    }
};
