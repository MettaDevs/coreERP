<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menahan pemilik kata sandi sementara di layar ganti kata sandi, dan tidak di mana pun lagi.
 *
 * Akun yang dibuatkan operator lahir dengan kata sandi yang pernah dilihat orang lain. Selama
 * sandi itu masih berlaku, sesi pemiliknya bukan miliknya sendiri — siapa pun yang sempat membaca
 * sandinya dapat masuk sebagai dia. Karena itu yang ditahan bukan hanya halaman sensitif melainkan
 * **seluruh aplikasi**: menahan setengahnya berarti menerima bahwa setengah yang lain dikerjakan
 * atas nama orang yang belum tentu dia.
 *
 * ## Yang dilepas, dan alasan tiap satunya
 *
 * - Layar ganti kata sandi beserta rute pembaruannya — kalau ini ikut ditahan, penjaganya menjadi
 *   kurungan tanpa pintu.
 * - Konfirmasi kata sandi. Layar ganti kata sandi berada di balik `RequirePassword`, jadi tanpa
 *   pengecualian ini penjaga kita dan penjaga itu saling melempar: yang satu mengarahkan ke layar
 *   ganti, yang satu mengarahkan kembali ke konfirmasi, dan peramban berputar sampai menyerah.
 * - Logout — orang yang salah masuk harus selalu bisa keluar.
 * - Aset, `/up`, dan `.well-known` — bukan halaman, dan mengarahkannya hanya merusak permintaan
 *   yang tidak pernah dilihat siapa pun.
 *
 * ## Kenapa ia aman dipasang pada seluruh grup web
 *
 * Karena pertanyaan pertamanya bukan "rute apa ini" melainkan "apakah orang ini ditandai".
 * Kolomnya berbawaan `false` dan hanya pintu operator yang menyalakannya, sehingga tamu, pendaftar
 * mandiri, dan setiap akun yang sudah ada melewati kelas ini tanpa satu pemeriksaan pun — bukan
 * "diizinkan setelah diperiksa", melainkan tidak pernah diperiksa sama sekali.
 */
final class WajibGantiSandi
{
    /**
     * Rute yang tetap dapat dicapai selama penandanya menyala.
     *
     * Nama rute, bukan path. Path berubah ketika seseorang merapikan URL dan penjaga yang memakai
     * path akan diam-diam berhenti melepaskan apa pun — kegagalan yang bentuknya kurungan tanpa
     * pintu, dan tidak ada test bawaan yang menangkapnya.
     */
    private const RUTE_TERBUKA = [
        'security.edit',
        'user-password.update',
        'logout',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
    ];

    /** Permintaan yang bukan halaman, jadi tidak ada gunanya diarahkan ke mana-mana. */
    private const JALUR_TERBUKA = ['up', 'build/*', 'storage/*', '.well-known/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = $request->user();

        if (! $pengguna instanceof User || ! $pengguna->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(...self::RUTE_TERBUKA) || $request->is(...self::JALUR_TERBUKA)) {
            return $next($request);
        }

        return redirect()->route('security.edit');
    }
}
