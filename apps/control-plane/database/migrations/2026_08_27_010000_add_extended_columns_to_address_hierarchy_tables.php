<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ref_countries', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_countries', 'timezone')) {
                $table->string('timezone', 50)->default('UTC+07:00')->after('phone_code');
            }
        });

        Schema::table('ref_provinces', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_provinces', 'timezone')) {
                $table->string('timezone', 50)->default('UTC+07:00')->after('name');
            }
            if (! Schema::hasColumn('ref_provinces', 'intrastat')) {
                $table->string('intrastat', 50)->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('ref_provinces', 'it_state_code')) {
                $table->string('it_state_code', 50)->nullable()->after('intrastat');
            }
            if (! Schema::hasColumn('ref_provinces', 'state_code')) {
                $table->string('state_code', 50)->nullable()->after('it_state_code');
            }
            if (! Schema::hasColumn('ref_provinces', 'default_state')) {
                $table->boolean('default_state')->default(false)->after('state_code');
            }
            if (! Schema::hasColumn('ref_provinces', 'union_territory')) {
                $table->boolean('union_territory')->default(false)->after('default_state');
            }
        });

        Schema::table('ref_regencies', function (Blueprint $table): void {
            if (! Schema::hasColumn('ref_regencies', 'it_county_code')) {
                $table->string('it_county_code', 50)->nullable()->after('type');
            }
            if (! Schema::hasColumn('ref_regencies', 'es_county_code')) {
                $table->string('es_county_code', 50)->nullable()->after('it_county_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ref_regencies', function (Blueprint $table): void {
            $table->dropColumn(['it_county_code', 'es_county_code']);
        });

        Schema::table('ref_provinces', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'intrastat', 'it_state_code', 'state_code', 'default_state', 'union_territory']);
        });

        Schema::table('ref_countries', function (Blueprint $table): void {
            $table->dropColumn(['timezone']);
        });
    }
};
