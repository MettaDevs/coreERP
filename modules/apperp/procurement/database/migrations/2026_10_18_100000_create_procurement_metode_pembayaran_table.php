<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('procurement_metode_pembayaran')) {
            Schema::create('procurement_metode_pembayaran', function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id')->index();
                $blueprint->string('creation_key', 160)->nullable();
                $blueprint->string('kode_bayar', 100);
                $blueprint->string('nama_metode_bayar', 255);
                $blueprint->text('keterangan')->nullable();
                $blueprint->boolean('aktif')->default(true);
                $blueprint->softDeletes();
                $blueprint->timestamps();

                $blueprint->unique(['tenant_id', 'id']);
                $blueprint->unique(['tenant_id', 'kode_bayar']);
                $blueprint->index(['tenant_id', 'aktif']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_metode_pembayaran');
    }
};
