<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `reset_annually` could only express a calendar year, which is not how an ERP resets a document series. It becomes
 * `reset_period`, covering never / calendar year / fiscal year / fiscal period.
 *
 * `period_key` widens because a fiscal key carries the fiscal year name and period ordinal, not just four digits.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $periodKeyTables = [
        'number_sequence_counters',
        'number_sequence_allocations',
        'number_sequence_reservations',
        'number_sequence_reusable_numbers',
        'number_sequence_issues',
        'number_sequence_continuous_pool',
    ];

    public function up(): void
    {
        Schema::table('tenant_number_sequences', function (Blueprint $table): void {
            $table->string('reset_period', 20)->default('never');
        });

        DB::table('tenant_number_sequences')->where('reset_annually', true)->update(['reset_period' => 'calendar_year']);
        DB::table('tenant_number_sequences')->where('reset_annually', false)->update(['reset_period' => 'never']);

        Schema::table('tenant_number_sequences', function (Blueprint $table): void {
            $table->dropColumn('reset_annually');
        });

        foreach ($this->periodKeyTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('period_key', 40)->default('all')->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->periodKeyTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('period_key', 10)->default('all')->change();
            });
        }

        Schema::table('tenant_number_sequences', function (Blueprint $table): void {
            $table->boolean('reset_annually')->default(false);
        });

        DB::table('tenant_number_sequences')->where('reset_period', 'calendar_year')->update(['reset_annually' => true]);

        Schema::table('tenant_number_sequences', function (Blueprint $table): void {
            $table->dropColumn('reset_period');
        });
    }
};
