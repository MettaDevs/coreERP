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
        Schema::create('procurement_harga_perkiraan', function (Blueprint $blueprint): void {
            $blueprint->ulid('id')->primary();
            $blueprint->ulid('tenant_id')->index();
            $blueprint->string('creation_key', 160)->nullable();
            $blueprint->char('creation_payload_hash', 64)->nullable();
            $blueprint->string('group_item', 100);
            $blueprint->string('jenis_item', 100);
            $blueprint->string('kode_item', 100);
            $blueprint->string('nama_item', 255);
            $blueprint->string('satuan', 50);
            $blueprint->decimal('harga_perkiraan', 18, 2)->default(0);
            $blueprint->date('tanggal_update')->nullable();
            $blueprint->text('keterangan')->nullable();

            AuditColumns::add($blueprint);
            $blueprint->softDeletes();
            $blueprint->timestamps();

            $blueprint->unique(['tenant_id', 'id']);
            $blueprint->unique(['tenant_id', 'creation_key']);
            $blueprint->index(['tenant_id', 'group_item']);
            $blueprint->index(['tenant_id', 'jenis_item']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX procurement_harga_perkiraan_kode_item_unique '.
            'ON procurement_harga_perkiraan (tenant_id, kode_item) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('procurement_harga_perkiraan');
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_harga_perkiraan');
    }
};
