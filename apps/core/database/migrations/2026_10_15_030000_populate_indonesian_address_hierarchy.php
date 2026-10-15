<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $import = require __DIR__.'/../data/import_indonesia_hierarchy.php';
        $import(__DIR__.'/../data');
    }

    public function down(): void
    {
        // Mundur image tidak menghapus master yang sudah dirujuk alamat.
    }
};
