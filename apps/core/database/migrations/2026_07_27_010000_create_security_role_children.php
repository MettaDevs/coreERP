<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchy security role mengikuti Dynamics 365: satu role dapat disusun di atas
 * role lain, dan parent mewarisi duty seluruh child-nya. Satu role boleh memiliki
 * lebih dari satu parent maupun lebih dari satu child, sehingga relasinya adalah
 * graph berarah tanpa siklus — bukan tree.
 *
 * Relasi ini tidak menggantikan organization hierarchy. Ia menyusun tanggung
 * jawab, bukan menempatkan organisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dibutuhkan agar child role dapat memakai composite foreign key sehingga
        // database menolak menautkan role milik tenant lain, bukan hanya service.
        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('security_role_children', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('parent_role_id');
            $table->ulid('child_role_id');
            $table->timestamps();

            $table->primary(['parent_role_id', 'child_role_id']);
            $table->index('child_role_id');

            $table->foreign(['tenant_id', 'parent_role_id'])
                ->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'child_role_id'])
                ->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_role_children');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
