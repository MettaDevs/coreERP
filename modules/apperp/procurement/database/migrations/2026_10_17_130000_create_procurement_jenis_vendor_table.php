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
        Schema::create('procurement_jenis_vendor', function (Blueprint $blueprint): void {
            $blueprint->ulid('id')->primary();
            $blueprint->ulid('tenant_id')->index();
            $blueprint->string('creation_key', 160)->nullable();
            $blueprint->string('kode_jenis', 100);
            $blueprint->string('nama_jenis_vendor', 255);
            $blueprint->text('keterangan')->nullable();
            $blueprint->boolean('aktif')->default(true);

            AuditColumns::add($blueprint);
            $blueprint->softDeletes();
            $blueprint->timestamps();

            $blueprint->unique(['tenant_id', 'id']);
            $blueprint->unique(['tenant_id', 'kode_jenis']);
            $blueprint->index(['tenant_id', 'aktif']);
        });

        AuditColumns::attach('procurement_jenis_vendor');
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_jenis_vendor');
    }
};
