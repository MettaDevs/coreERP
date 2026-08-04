<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hierarchy_purposes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_hierarchies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('organization_hierarchy_purposes', function (Blueprint $table) {
            $table->foreignUlid('hierarchy_id')->constrained('organization_hierarchies')->cascadeOnDelete();
            $table->foreignUlid('purpose_id')->constrained('hierarchy_purposes')->restrictOnDelete();
            $table->primary(['hierarchy_id', 'purpose_id']);
        });

        Schema::create('organization_hierarchy_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('hierarchy_id')->constrained('organization_hierarchies')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('draft');
            $table->timestamp('effective_from');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['hierarchy_id', 'version_number']);
        });

        Schema::create('organization_hierarchy_nodes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('version_id')->constrained('organization_hierarchy_versions')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->ulid('parent_node_id')->nullable();
            $table->timestamps();
            $table->unique(['version_id', 'organization_id']);
        });
        Schema::table('organization_hierarchy_nodes', function (Blueprint $table) {
            $table->foreign('parent_node_id')->references('id')->on('organization_hierarchy_nodes')->restrictOnDelete();
        });

        Schema::create('organization_hierarchy_closures', function (Blueprint $table) {
            $table->foreignUlid('version_id')->constrained('organization_hierarchy_versions')->cascadeOnDelete();
            $table->foreignUlid('ancestor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('descendant_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('distance');
            $table->primary(['version_id', 'ancestor_organization_id', 'descendant_organization_id']);
        });

        $now = now();
        DB::table('hierarchy_purposes')->insert([
            ['id' => (string) Str::ulid(), 'code' => 'management', 'name' => 'Management reporting', 'description' => 'Struktur untuk pelaporan dan pengelolaan internal.', 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::ulid(), 'code' => 'policy', 'name' => 'Business policy', 'description' => 'Struktur untuk penerapan kebijakan pada unit organisasi.', 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::ulid(), 'code' => 'procurement', 'name' => 'Procurement', 'description' => 'Struktur untuk kebijakan dan proses pengadaan.', 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::ulid(), 'code' => 'establishment', 'name' => 'Establishment', 'description' => 'Struktur unit kerja dan lokasi operasional.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_hierarchy_closures');
        Schema::dropIfExists('organization_hierarchy_nodes');
        Schema::dropIfExists('organization_hierarchy_versions');
        Schema::dropIfExists('organization_hierarchy_purposes');
        Schema::dropIfExists('organization_hierarchies');
        Schema::dropIfExists('hierarchy_purposes');
    }
};
