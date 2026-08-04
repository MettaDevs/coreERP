<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tr_permintaan_pengadaan_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary(); $table->ulid('tenant_id')->index(); $table->string('creation_key', 160); $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index(); $table->ulid('requesting_org_unit_id')->index(); $table->string('requester_user_id', 64); $table->date('requested_on');
            $table->string('status', 30)->default('draft'); $table->ulid('workflow_instance_id')->nullable(); $table->text('description')->nullable(); $table->unsignedInteger('version')->default(1); $table->softDeletes(); $table->timestamps();
            $table->unique(['tenant_id', 'id']); $table->unique(['tenant_id', 'creation_key']); $table->unique(['tenant_id', 'kode']); $table->unique(['tenant_id', 'workflow_instance_id']); $table->index(['tenant_id', 'legal_entity_id', 'requesting_org_unit_id', 'status']);
        });
        Schema::create('tr_permintaan_pengadaan_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary(); $table->ulid('tenant_id')->index(); $table->ulid('request_id'); $table->unsignedInteger('line_number'); $table->ulid('planning_detail_id')->nullable(); $table->ulid('jenis_aset_id'); $table->ulid('satuan_id'); $table->string('asset_name', 150); $table->decimal('quantity', 18, 4); $table->text('specification'); $table->text('note')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'request_id', 'line_number']); $table->foreign(['tenant_id', 'request_id'])->references(['tenant_id', 'id'])->on('tr_permintaan_pengadaan_aset')->restrictOnDelete(); $table->foreign(['tenant_id', 'jenis_aset_id'])->references(['tenant_id', 'id'])->on('m_jenis_aset')->restrictOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('tr_permintaan_pengadaan_aset_details'); Schema::dropIfExists('tr_permintaan_pengadaan_aset'); }
};
