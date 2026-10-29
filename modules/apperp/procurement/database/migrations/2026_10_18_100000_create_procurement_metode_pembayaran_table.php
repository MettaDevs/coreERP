<?php

declare(strict_types=1);

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_metode_pembayaran', function (Blueprint $blueprint): void {
            $blueprint->ulid('id')->primary();
            $blueprint->ulid('tenant_id')->index();
            $blueprint->string('creation_key', 160)->nullable();
            $blueprint->char('creation_payload_hash', 64)->nullable();
            $blueprint->string('kode_bayar', 100);
            $blueprint->string('nama_metode_bayar', 255);
            $blueprint->text('keterangan')->nullable();
            $blueprint->boolean('aktif')->default(true);

            AuditColumns::add($blueprint);
            $blueprint->softDeletes();
            $blueprint->timestamps();

            $blueprint->unique(['tenant_id', 'id']);
            $blueprint->unique(['tenant_id', 'creation_key']);
            $blueprint->index(['tenant_id', 'aktif']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX procurement_metode_pembayaran_kode_unique '.
            'ON procurement_metode_pembayaran (tenant_id, kode_bayar) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('procurement_metode_pembayaran');
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_metode_pembayaran');
    }
};
