<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ref_postal_codes', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_postal_codes', 'area_name')) {
                $table->string('area_name', 200)->nullable()->after('postal_code');
            }
            if (! Schema::hasColumn('ref_postal_codes', 'source')) {
                $table->string('source', 100)->default('POS_INDONESIA')->after('area_name');
            }
            if (! Schema::hasColumn('ref_postal_codes', 'source_reference')) {
                $table->string('source_reference', 255)->nullable()->after('source');
            }
            if (! Schema::hasColumn('ref_postal_codes', 'status')) {
                $table->string('status', 20)->default('active')->after('source_reference');
            }
        });

        Schema::table('ref_streets', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_streets', 'postal_code')) {
                $table->string('postal_code', 10)->nullable()->after('name');
            }
            if (! Schema::hasColumn('ref_streets', 'postal_code_id')) {
                $table->foreignUlid('postal_code_id')->nullable()->after('postal_code')->constrained('ref_postal_codes')->nullOnDelete();
            }
            if (! Schema::hasColumn('ref_streets', 'override_postal_code')) {
                $table->boolean('override_postal_code')->default(false)->after('postal_code_id');
            }
        });

        Schema::table('ref_group_of_houses', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_group_of_houses', 'postal_code')) {
                $table->string('postal_code', 10)->nullable()->after('name');
            }
            if (! Schema::hasColumn('ref_group_of_houses', 'postal_code_id')) {
                $table->foreignUlid('postal_code_id')->nullable()->after('postal_code')->constrained('ref_postal_codes')->nullOnDelete();
            }
            if (! Schema::hasColumn('ref_group_of_houses', 'override_postal_code')) {
                $table->boolean('override_postal_code')->default(false)->after('postal_code_id');
            }
        });

        Schema::table('ref_land_plots', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_land_plots', 'postal_code')) {
                $table->string('postal_code', 10)->nullable()->after('name');
            }
            if (! Schema::hasColumn('ref_land_plots', 'postal_code_id')) {
                $table->foreignUlid('postal_code_id')->nullable()->after('postal_code')->constrained('ref_postal_codes')->nullOnDelete();
            }
            if (! Schema::hasColumn('ref_land_plots', 'override_postal_code')) {
                $table->boolean('override_postal_code')->default(false)->after('postal_code_id');
            }
        });

        Schema::table('ref_buildings', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_buildings', 'postal_code')) {
                $table->string('postal_code', 10)->nullable()->after('name');
            }
            if (! Schema::hasColumn('ref_buildings', 'postal_code_id')) {
                $table->foreignUlid('postal_code_id')->nullable()->after('postal_code')->constrained('ref_postal_codes')->nullOnDelete();
            }
            if (! Schema::hasColumn('ref_buildings', 'override_postal_code')) {
                $table->boolean('override_postal_code')->default(false)->after('postal_code_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ref_buildings', function (Blueprint $table): void {
            $table->dropForeign(['postal_code_id']);
            $table->dropColumn(['postal_code', 'postal_code_id', 'override_postal_code']);
        });

        Schema::table('ref_land_plots', function (Blueprint $table): void {
            $table->dropForeign(['postal_code_id']);
            $table->dropColumn(['postal_code', 'postal_code_id', 'override_postal_code']);
        });

        Schema::table('ref_group_of_houses', function (Blueprint $table): void {
            $table->dropForeign(['postal_code_id']);
            $table->dropColumn(['postal_code', 'postal_code_id', 'override_postal_code']);
        });

        Schema::table('ref_streets', function (Blueprint $table): void {
            $table->dropForeign(['postal_code_id']);
            $table->dropColumn(['postal_code', 'postal_code_id', 'override_postal_code']);
        });

        Schema::table('ref_postal_codes', function (Blueprint $table): void {
            $table->dropColumn(['area_name', 'source', 'source_reference', 'status']);
        });
    }
};
