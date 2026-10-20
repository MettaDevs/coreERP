<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('procurement_prakualifikasi')) {
            Schema::create('procurement_prakualifikasi', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('nomor_prakualifikasi', 100);
                $blueprint->date('tanggal_prakualifikasi');

                // Relasi / Snapshot data vendor rekanan
                $blueprint->ulid('vendor_id')->nullable();
                $blueprint->string('kode_vendor', 100);
                $blueprint->string('nama_vendor', 255);
                $blueprint->string('jenis_vendor', 100)->nullable();
                $blueprint->string('nama_pic', 150)->nullable();
                $blueprint->string('npwp_ktp', 100)->nullable();
                $blueprint->string('telepon', 50)->nullable();
                $blueprint->string('email', 150)->nullable();
                $blueprint->string('media_sosial', 150)->nullable();
                $blueprint->text('alamat')->nullable();

                // Evaluasi legalitas & operasional rekanan
                $blueprint->string('status_vendor', 100)->default('Vendor Resmi');
                $blueprint->string('status_badan_hukum', 50)->default('Badan Hukum');
                $blueprint->string('status_kualifikasi', 50)->default('Lolos');
                $blueprint->string('waktu_kesediaan_item', 100)->nullable();
                $blueprint->integer('jumlah_karyawan')->nullable();
                $blueprint->text('catatan')->nullable();

                $blueprint->softDeletes();
                $blueprint->timestamps();

                // Composite uniqueness and foreign key support
                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'nomor_prakualifikasi']);
                $blueprint->index(['tenant_id', 'kode_vendor']);
                $blueprint->index(['tenant_id', 'status_kualifikasi']);
                $blueprint->index(['tenant_id', 'tanggal_prakualifikasi']);
            });
        } else {
            // Pastikan kolom status_vendor tersedia jika tabel sudah ada sebelumnya
            if (! Schema::hasColumn('procurement_prakualifikasi', 'status_vendor')) {
                Schema::table('procurement_prakualifikasi', function (Blueprint $blueprint): void {
                    $blueprint->string('status_vendor', 100)->default('Vendor Resmi')->nullable();
                });
            }
        }

        if (! Schema::hasTable('procurement_prakualifikasi_dokumen')) {
            Schema::create('procurement_prakualifikasi_dokumen', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->ulid('prakualifikasi_id');
                $blueprint->string('kategori', 50)->default('wajib'); // wajib / tambahan
                $blueprint->integer('urutan')->default(0);
                $blueprint->string('nama_dokumen', 255);
                $blueprint->boolean('ada')->default(false);
                $blueprint->string('keterangan', 255)->nullable();
                $blueprint->timestamps();

                $blueprint->foreign(['tenant_id', 'prakualifikasi_id'])
                    ->references(['tenant_id', 'id'])
                    ->on('procurement_prakualifikasi')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('procurement_prakualifikasi_item')) {
            Schema::create('procurement_prakualifikasi_item', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->ulid('prakualifikasi_id');
                $blueprint->integer('urutan')->default(0);
                $blueprint->string('jenis_item', 100)->nullable();
                $blueprint->string('kode_item', 100)->nullable();
                $blueprint->string('nama_item', 255);
                $blueprint->string('satuan', 50)->nullable();
                $blueprint->string('kesediaan', 100)->nullable();
                $blueprint->text('keterangan')->nullable();
                $blueprint->timestamps();

                $blueprint->foreign(['tenant_id', 'prakualifikasi_id'])
                    ->references(['tenant_id', 'id'])
                    ->on('procurement_prakualifikasi')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_prakualifikasi_item');
        Schema::dropIfExists('procurement_prakualifikasi_dokumen');
        Schema::dropIfExists('procurement_prakualifikasi');
    }
};
