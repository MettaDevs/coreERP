<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequence_continuous_pool', function (Blueprint $table): void {
            $table->foreignUlid('sequence_id')->constrained('tenant_number_sequences')->cascadeOnDelete();
            $table->string('scope_key', 180);
            $table->string('period_key', 10)->default('all');
            $table->unsignedBigInteger('numeric_value');
            $table->string('status', 30)->default('available');
            $table->ulid('reservation_id')->nullable();
            $table->timestamps();
            $table->primary(['sequence_id', 'scope_key', 'period_key', 'numeric_value']);
            $table->index(['sequence_id', 'scope_key', 'period_key', 'status', 'numeric_value'], 'number_sequence_continuous_pool_available');
            $table->index('reservation_id');
        });

        DB::table('number_sequence_profiles')->where('code', 'continuous-strict')->update([
            'preallocation_enabled' => true,
            'preallocation_quantity' => 5,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('number_sequence_profiles')->where('code', 'continuous-strict')->update([
            'preallocation_enabled' => false,
            'preallocation_quantity' => 0,
            'updated_at' => now(),
        ]);
        Schema::dropIfExists('number_sequence_continuous_pool');
    }
};
