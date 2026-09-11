<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `reconciliation_pending` is 23 characters and the column was varchar(20), so every recovery of an expired
 * continuous reservation failed on PostgreSQL. SQLite does not enforce varchar length, which is why the original
 * test suite passed while the production path could not run at all.
 *
 * The pool table already used varchar(30); reservations now match it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_sequence_reservations', function (Blueprint $table): void {
            $table->string('status', 30)->default('reserved')->change();
        });
    }

    public function down(): void
    {
        Schema::table('number_sequence_reservations', function (Blueprint $table): void {
            $table->string('status', 20)->default('reserved')->change();
        });
    }
};
