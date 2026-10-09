<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_work_items', function (Blueprint $table): void {
            $table->string('review_url', 1000)->nullable();
            $table->timestamp('email_notified_at')->nullable();
            $table->timestamp('email_next_attempt_at')->nullable();
            $table->unsignedInteger('email_attempts')->default(0);
            $table->string('email_last_error', 250)->nullable();
        });
    }

    public function down(): void {}
};
