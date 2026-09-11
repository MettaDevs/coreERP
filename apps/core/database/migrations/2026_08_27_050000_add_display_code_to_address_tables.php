<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ref_administrative_divisions') && ! Schema::hasColumn('ref_administrative_divisions', 'display_code')) {
            Schema::table('ref_administrative_divisions', function (Blueprint $table): void {
                $table->string('display_code', 50)->nullable()->index()->after('official_code');
            });
        }

        if (Schema::hasTable('ref_provinces') && ! Schema::hasColumn('ref_provinces', 'display_code')) {
            Schema::table('ref_provinces', function (Blueprint $table): void {
                $table->string('display_code', 50)->nullable()->index()->after('code');
            });
        }

        if (Schema::hasTable('ref_regencies') && ! Schema::hasColumn('ref_regencies', 'display_code')) {
            Schema::table('ref_regencies', function (Blueprint $table): void {
                $table->string('display_code', 50)->nullable()->index()->after('code');
            });
        }

        if (Schema::hasTable('ref_districts') && ! Schema::hasColumn('ref_districts', 'display_code')) {
            Schema::table('ref_districts', function (Blueprint $table): void {
                $table->string('display_code', 50)->nullable()->index()->after('code');
            });
        }

        if (Schema::hasTable('ref_villages') && ! Schema::hasColumn('ref_villages', 'display_code')) {
            Schema::table('ref_villages', function (Blueprint $table): void {
                $table->string('display_code', 50)->nullable()->index()->after('code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ref_administrative_divisions', 'display_code')) {
            Schema::table('ref_administrative_divisions', function (Blueprint $table): void {
                $table->dropColumn('display_code');
            });
        }
        if (Schema::hasColumn('ref_provinces', 'display_code')) {
            Schema::table('ref_provinces', function (Blueprint $table): void {
                $table->dropColumn('display_code');
            });
        }
        if (Schema::hasColumn('ref_regencies', 'display_code')) {
            Schema::table('ref_regencies', function (Blueprint $table): void {
                $table->dropColumn('display_code');
            });
        }
        if (Schema::hasColumn('ref_districts', 'display_code')) {
            Schema::table('ref_districts', function (Blueprint $table): void {
                $table->dropColumn('display_code');
            });
        }
        if (Schema::hasColumn('ref_villages', 'display_code')) {
            Schema::table('ref_villages', function (Blueprint $table): void {
                $table->dropColumn('display_code');
            });
        }
    }
};
