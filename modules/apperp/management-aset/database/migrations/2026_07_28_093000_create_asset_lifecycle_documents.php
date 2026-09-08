<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_dokumen_siklus_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('jenis_dokumen', 40);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            $table->ulid('asset_id')->nullable();
            $table->date('tanggal');
            $table->string('status', 30)->default('draft');
            $table->decimal('nilai', 18, 2)->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'kode']);
            $table->index(['tenant_id', 'jenis_dokumen', 'tanggal']);
            $table->foreign(['tenant_id', 'asset_id'])->references(['tenant_id', 'id'])->on('t_aset')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_dokumen_siklus_aset');
    }
};
