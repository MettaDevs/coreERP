<?php

/*
 * Router untuk server bawaan PHP, supaya Core dapat dijalankan tanpa `php artisan serve`.
 *
 * Alasannya bukan selera. `php artisan serve` menyalakan `php -S` sebagai **proses anak**, dan pada
 * Windows pemanggilan itu dapat ditolak dengan "CreateProcess failed: The requested operation
 * requires elevation" — kegagalan yang tidak menyebut Laravel maupun porta sama sekali, dan yang
 * membuat perintah itu tidak dapat dipakai di sebagian mesin. Menjalankan `php -S` langsung
 * melewati perantaranya.
 *
 * Jalur publiknya dihitung dari letak berkas ini, bukan dari `getcwd()`. Router bawaan Laravel
 * memakai `getcwd()`, sehingga ia hanya benar bila prosesnya kebetulan dijalankan dari dalam
 * `public/` — dan `-t public` tidak mengubah cwd. Gejalanya "Failed opening required index.php",
 * yang menunjuk berkas yang memang tidak pernah ada di sana.
 *
 * Jalankan dari folder ini, dan `-t public` **wajib**:
 *
 *     php -S 127.0.0.1:8001 -t public server.php
 *
 * Tanpa `-t`, docroot server bawaan adalah folder kerja — yaitu folder aplikasi ini, bukan
 * `public/`. Halamannya tetap terkirim karena router ini yang menanganinya, tetapi setiap berkas
 * statis dicari di tempat yang salah dan dijawab 404. Gejalanya halaman kosong dengan judul yang
 * benar: HTML sampai, seluruh CSS dan JavaScript-nya tidak.
 */

$publicPath = __DIR__.'/public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

// Berkas yang benar-benar ada disajikan apa adanya oleh server bawaan; sisanya masuk ke Laravel.
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
