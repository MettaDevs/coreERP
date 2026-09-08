<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tr_perencanaan_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            $table->ulid('planning_org_unit_id')->index();
            $table->date('planned_on');
            $table->unsignedSmallInteger('planning_year');
            $table->string('planning_type', 30)->default('regular');
            $table->string('funding_source', 250)->nullable();
            $table->string('responsible_user_id', 64)->nullable();
            $table->decimal('total_estimated_value', 18, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'kode']);
            $table->index(['tenant_id', 'planning_year', 'status']);
        });

        Schema::create('tr_perencanaan_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('planning_id');
            $table->unsignedInteger('line_number');
            $table->ulid('jenis_aset_id');
            $table->string('asset_name', 150);
            $table->string('unit', 30);
            $table->decimal('quantity', 18, 4);
            $table->text('requested_specification');
            $table->decimal('estimated_unit_price', 18, 2)->default(0);
            $table->decimal('estimated_total_price', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'planning_id', 'line_number']);
            $table->index(['tenant_id', 'jenis_aset_id']);
            $table->foreign(['tenant_id', 'planning_id'])
                ->references(['tenant_id', 'id'])
                ->on('tr_perencanaan_aset')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'jenis_aset_id'])
                ->references(['tenant_id', 'id'])
                ->on('m_jenis_aset')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_perencanaan_aset_details');
        Schema::dropIfExists('tr_perencanaan_aset');
    }
};
