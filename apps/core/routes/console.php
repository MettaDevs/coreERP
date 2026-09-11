<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('number-sequences:recover')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('workflow-events:publish')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Hasil ekspor laporan punya masa simpan; lihat config/reporting.php.
Schedule::command('reporting:purge-exports')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();
