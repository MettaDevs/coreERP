<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createMaster('m_maintenance_job_type', function (Blueprint $table): void {
            $table->string('category_code', 40)->default('preventive');
            $table->boolean('maintenance_downtime_activities')->default(false);
        });

        Schema::create('m_maintenance_job_type_variant', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->ulid('maintenance_job_type_id');
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'kode'], 'm_mnt_job_variant_code_uq');
            $table->unique(['tenant_id', 'creation_key'], 'm_mnt_job_variant_creation_uq');
            $table->unique(['tenant_id', 'id'], 'm_mnt_job_variant_tenant_id_uq');
            $table->index(['tenant_id', 'maintenance_job_type_id'], 'm_mnt_job_variant_job_type_ix');
            $table->foreign(['tenant_id', 'maintenance_job_type_id'], 'm_mnt_job_variant_job_type_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type')->restrictOnDelete();
        });

        Schema::create('m_maintenance_job_type_requirement', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('maintenance_job_type_id');
            $table->string('requirement_type', 20);
            $table->string('nama', 150);
            $table->string('level', 50)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'maintenance_job_type_id', 'requirement_type', 'nama'], 'm_mnt_job_req_identity_uq');
            $table->foreign(['tenant_id', 'maintenance_job_type_id'], 'm_mnt_job_req_job_type_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type')->cascadeOnDelete();
        });

        Schema::create('m_maintenance_checklist_variable', function (Blueprint $table): void {
            $this->addMasterColumns($table, 'm_mnt_variable');
        });

        Schema::create('m_maintenance_checklist_variable_value', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('variable_id');
            $table->decimal('line_number', 8, 1);
            $table->string('value', 255);
            $table->string('result_code', 20);
            $table->timestamps();
            $table->unique(['tenant_id', 'variable_id', 'line_number'], 'm_mnt_var_value_line_uq');
            $table->foreign(['tenant_id', 'variable_id'], 'm_mnt_var_value_variable_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_checklist_variable')->cascadeOnDelete();
        });

        Schema::create('m_maintenance_checklist_template', function (Blueprint $table): void {
            $this->addMasterColumns($table, 'm_mnt_template');
        });

        Schema::create('m_maintenance_checklist_template_line', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('template_id');
            $table->decimal('line_number', 8, 1);
            $table->string('type', 20);
            $table->ulid('variable_id')->nullable();
            $table->ulid('nested_template_id')->nullable();
            $table->string('unit', 40)->nullable();
            $table->string('nama', 255);
            $table->timestamps();
            $table->unique(['tenant_id', 'template_id', 'line_number'], 'm_mnt_tpl_line_number_uq');
            $table->foreign(['tenant_id', 'template_id'], 'm_mnt_tpl_line_template_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_checklist_template')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'variable_id'], 'm_mnt_tpl_line_variable_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_checklist_variable')->restrictOnDelete();
            $table->foreign(['tenant_id', 'nested_template_id'], 'm_mnt_tpl_line_nested_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_checklist_template')->restrictOnDelete();
        });

        Schema::create('m_maintenance_job_type_default', function (Blueprint $table): void {
            $this->addMasterColumns($table, 'm_mnt_default');
            $table->ulid('maintenance_job_type_id');
            $table->ulid('variant_id')->nullable();
            $table->string('trade', 100)->nullable();
            $table->ulid('functional_location_id')->nullable();
            $table->ulid('jenis_aset_id')->nullable();
            $table->ulid('pabrikan_aset_id')->nullable();
            $table->ulid('model_aset_id')->nullable();
            $table->ulid('asset_id')->nullable();
            $table->decimal('hours', 12, 2)->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('expenses_count')->default(0);
            $table->unsignedInteger('fees_count')->default(0);
            $table->ulid('checklist_template_id')->nullable();
            $table->index(['tenant_id', 'maintenance_job_type_id'], 'm_mnt_default_job_type_ix');
            $table->foreign(['tenant_id', 'maintenance_job_type_id'], 'm_mnt_default_job_type_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type')->restrictOnDelete();
            $table->foreign(['tenant_id', 'variant_id'], 'm_mnt_default_variant_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type_variant')->restrictOnDelete();
            $table->foreign(['tenant_id', 'functional_location_id'], 'm_mnt_default_location_fk')
                ->references(['tenant_id', 'id'])->on('m_lokasi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'jenis_aset_id'], 'm_mnt_default_asset_type_fk')
                ->references(['tenant_id', 'id'])->on('m_jenis_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pabrikan_aset_id'], 'm_mnt_default_manufacturer_fk')
                ->references(['tenant_id', 'id'])->on('m_pabrikan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'model_aset_id'], 'm_mnt_default_model_fk')
                ->references(['tenant_id', 'id'])->on('m_model_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asset_id'], 'm_mnt_default_asset_fk')
                ->references(['tenant_id', 'id'])->on('tr_penerimaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'checklist_template_id'], 'm_mnt_default_checklist_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_checklist_template')->restrictOnDelete();
        });

        Schema::create('m_maintenance_job_type_asset_type', function (Blueprint $table): void {
            $table->ulid('tenant_id');
            $table->ulid('job_type_id');
            $table->ulid('jenis_aset_id');
            $table->timestamps();
            $table->primary(['tenant_id', 'job_type_id', 'jenis_aset_id'], 'm_mnt_job_asset_type_pk');
            $table->foreign(['tenant_id', 'job_type_id'], 'm_mnt_job_asset_type_job_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'jenis_aset_id'], 'm_mnt_job_asset_type_asset_fk')
                ->references(['tenant_id', 'id'])->on('m_jenis_aset')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_maintenance_job_type_asset_type');
        Schema::dropIfExists('m_maintenance_job_type_default');
        Schema::dropIfExists('m_maintenance_checklist_template_line');
        Schema::dropIfExists('m_maintenance_checklist_template');
        Schema::dropIfExists('m_maintenance_checklist_variable_value');
        Schema::dropIfExists('m_maintenance_checklist_variable');
        Schema::dropIfExists('m_maintenance_job_type_requirement');
        Schema::dropIfExists('m_maintenance_job_type_variant');
        Schema::dropIfExists('m_maintenance_job_type');
    }

    private function createMaster(string $table, ?callable $columns = null): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($columns): void {
            $this->addMasterColumns($blueprint, 'm_mnt_job_type');
            if ($columns) {
                $columns($blueprint);
            }
        });
    }

    private function addMasterColumns(Blueprint $table, string $prefix): void
    {
        $table->ulid('id')->primary();
        $table->ulid('tenant_id')->index();
        $table->string('creation_key', 160);
        $table->string('kode', 50);
        $table->string('nama', 150);
        $table->text('keterangan')->nullable();
        $table->boolean('aktif')->default(true);
        $table->softDeletes();
        $table->timestamps();
        $table->unique(['tenant_id', 'kode'], $prefix.'_code_uq');
        $table->unique(['tenant_id', 'creation_key'], $prefix.'_creation_uq');
        $table->unique(['tenant_id', 'id'], $prefix.'_tenant_id_uq');
    }
};
