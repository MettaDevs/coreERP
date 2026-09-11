<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes that only matter once the tables are large.
 *
 * The allocation lookup filters on `next_number <= last_number`, which no plain index can express. Measured on a
 * sequence carrying 20k exhausted blocks, the planner reported "Rows Removed by Filter: 20002" and 451 buffer reads
 * for a query that should touch one row. The service now prunes exhausted blocks, and this partial index keeps the
 * lookup cheap even before pruning catches up.
 *
 * The reservation index supports the outstanding-reservation guard, which runs on every continuous reserve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_sequence_reservations', function (Blueprint $table): void {
            $table->index(['sequence_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS number_sequence_allocations_live
                ON number_sequence_allocations (sequence_id, scope_key, period_key, created_at)
                WHERE next_number <= last_number');
            DB::statement('CREATE INDEX IF NOT EXISTS number_sequence_continuous_pool_confirmed
                ON number_sequence_continuous_pool (updated_at) WHERE status = \'confirmed\'');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS number_sequence_continuous_pool_confirmed');
            DB::statement('DROP INDEX IF EXISTS number_sequence_allocations_live');
        }

        Schema::table('number_sequence_reservations', function (Blueprint $table): void {
            $table->dropIndex(['sequence_id', 'status']);
        });
    }
};
