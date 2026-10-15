<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class IndonesianAddressHierarchySeeder extends Seeder
{
    public function run(): void
    {
        $import = require __DIR__.'/../data/import_indonesia_hierarchy.php';
        $import(__DIR__.'/../data');
    }
}
