<?php

declare(strict_types=1);

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_termin_pembayaran', function (Blueprint $blueprint): void {
            $blueprint->ulid('id')->primary();
            $blueprint->ulid('tenant_id')->index();
            $blueprint->string('creation_key', 160)->nullable();
            $blueprint->string('kode_termin', 100);
            $blueprint->string('nama_termin_bayar', 255);
            $blueprint->text('keterangan')->nullable();
            $blueprint->boolean('aktif')->default(true);

            AuditColumns::add($blueprint);
            $blueprint->softDeletes();
            $blueprint->timestamps();

            $blueprint->unique(['tenant_id', 'id']);
            $blueprint->unique(['tenant_id', 'kode_termin']);
            $blueprint->index(['tenant_id', 'aktif']);
        });

        AuditColumns::attach('procurement_termin_pembayaran');
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_termin_pembayaran');
    }
};
