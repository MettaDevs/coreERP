<?php

use App\Console\Commands\HapusLingkunganPermanen;
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

/*
 * Daur hidup lingkungan demo, dua langkah yang sengaja dipisah waktunya.
 *
 * Sapuan hanya menghapus lunak — ia menutup demo yang masa berlakunya lewat dan menjadwalkan kapan
 * isinya boleh hilang. Pembuangan permanen yang benar-benar membuang, dan hanya atas yang masa
 * tenggangnya sudah habis berhari-hari sebelumnya. Keduanya karena itu tidak pernah dapat
 * mengenai lingkungan yang sama pada malam yang sama; setengah jam jarak di sini semata supaya
 * keluaran keduanya tidak berhimpitan di log.
 *
 * Jam tiga pagi: sesudah pemulihan urutan nomor pukul dua, dan jauh dari jam kerja mana pun.
 *
 * `Schedule::environments()` tidak dipakai, dan tidak dapat dipakai. Ia membaca `APP_ENV`, yaitu
 * nama lingkungan **proses PHP** — sementara yang menentukan di sini isi registry, yang hidup di
 * database. Pemasangan on-prem menjalankan `APP_ENV=production` persis seperti SaaS, jadi
 * menyaring dengan nama itu tidak membedakan keduanya sama sekali.
 *
 * Yang membedakannya `skip()`, dan hanya perintah yang merusak yang memakainya: on-prem tidak
 * pernah punya lingkungan yang dihapus lunak, jadi di sana `environment:hapus-permanen` tidak
 * perlu bangun sama sekali. Sapuan tidak diberi penjaga serupa karena ia memang tidak berbahaya
 * ketika tidak ada yang perlu disapu — dan penjaga yang tidak menjaga apa-apa hanyalah satu query
 * tambahan yang kelak salah.
 */
Schedule::command('environment:sapu-kedaluwarsa')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('environment:hapus-permanen')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->skip(fn (): bool => HapusLingkunganPermanen::tidakAdaYangPerluDibuang());
