<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sod_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('first_duty_code', 160);
            $table->string('second_duty_code', 160);
            $table->string('severity', 20);
            $table->text('risk');
            $table->boolean('allows_mitigation')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'first_duty_code', 'second_duty_code']);
        });

        Schema::create('sod_conflicts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->foreignUlid('sod_rule_id')->constrained('sod_rules')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('mitigation_note')->nullable();
            $table->foreignUlid('approved_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'sod_rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sod_conflicts');
        Schema::dropIfExists('sod_rules');
    }
};
