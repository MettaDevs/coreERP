<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Identity dan user milik Core; app tidak menyimpannya.
     * Data awal tenant dibuat lewat API/event provisioning app, bukan seeder.
     */
    public function run(): void
    {
        //
    }
}
