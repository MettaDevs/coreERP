<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Melepaskan sebuah permintaan hanya bila ia benar-benar datang dari pusat admin.
 *
 * ## Kenapa bukan `AuthenticateAppService` yang sudah ada
 *
 * Penjaga itu menuntut tiga hal sekaligus: id app, token layanannya, dan tenant yang sedang
 * dilayani — lalu memastikan app itu **terpasang pada tenant itu**. Bentuknya benar untuk apa yang
 * dirancangnya, yaitu app module yang memanggil Core atas nama satu pelanggan.
 *
 * Pusat admin bukan app module. Ia tidak terpasang pada tenant mana pun, dan justru sedang bekerja
 * pada tenant yang belum punya apa-apa — pada rute ini, tenantnya bahkan belum ada saat
 * permintaannya tiba. Memaksakan penjaga itu berarti menerbitkan kredensial app palsu untuk setiap
 * pelanggan, dan kredensial palsu adalah kredensial yang tidak pernah dicabut siapa pun.
 *
 * Jadi arah pusat → Core memakai penjaganya sendiri: satu token bersama, dipegang dua proses milik
 * vendor yang sama. Ia sengaja sederhana, dan batasnya ditulis di sini supaya tidak ada yang
 * mengira ia lebih dari yang seharusnya — token bersama tidak menyebut **siapa** operatornya, jadi
 * jejak audit per orang tetap harus datang dari sisi konsol.
 *
 * ## Kenapa token kosong berarti tertutup, bukan terbuka
 *
 * Pemasangan yang tidak menyetel tokennya — on-prem, lingkungan lokal, dan setiap test yang tidak
 * menyinggung rute ini — **tidak punya** pusat admin. Perilaku yang benar di sana adalah menolak
 * semua orang, dan itu justru yang paling penting untuk ditulis eksplisit: bandingan string kosong
 * lawan string kosong bernilai benar, sehingga penjaga yang lupa memeriksanya akan membuka rute
 * pembuatan tenant kepada siapa pun yang mengirim header kosong.
 */
final class ControlPlaneOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('coreerp.control_plane_token');
        $presented = $request->bearerToken();

        if (! is_string($expected) || $expected === '') {
            abort(401, 'Pemasangan ini tidak menerima perintah pusat admin.');
        }

        // `hash_equals` dan bukan `===`: keduanya benar, tetapi yang ini tidak berhenti lebih awal
        // pada karakter pertama yang berbeda, sehingga lamanya jawaban tidak membocorkan berapa
        // banyak karakter tebakan yang sudah tepat.
        if (! is_string($presented) || ! hash_equals($expected, $presented)) {
            abort(401, 'Token pusat admin tidak dikenali.');
        }

        return $next($request);
    }
}
