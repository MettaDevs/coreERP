<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composite keys are created before t_aset in the preceding migration.
    }

    public function down(): void
    {
        // The preceding migration owns the composite keys.
    }
};
