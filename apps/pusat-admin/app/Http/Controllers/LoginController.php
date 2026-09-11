<?php

declare(strict_types=1);

namespace PusatAdmin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as HalamanInertia;

/**
 * Masuk memakai akun Core yang sama.
 *
 * Konsol ini tidak punya jalur pendaftaran, undangan, maupun setel ulang kata sandi — semuanya
 * tetap di Core dan tidak disentuh. Selama keputusan tentang letak identitas belum diambil,
 * menaruh salinan kedua dari jalur-jalur itu di sini hanya menambah tempat yang bisa salah.
 */
class LoginController extends Controller
{
    public function form(): HalamanInertia
    {
        return Inertia::render('login');
    }

    public function masuk(Request $request): RedirectResponse
    {
        $isian = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($isian, $request->boolean('ingat'))) {
            // Pesannya sengaja tidak membedakan "email tidak ada" dari "kata sandi salah".
            // Membedakannya mengubah formulir ini menjadi alat untuk mencari tahu siapa saja yang
            // punya akun.
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/lingkungan');
    }

    public function keluar(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
