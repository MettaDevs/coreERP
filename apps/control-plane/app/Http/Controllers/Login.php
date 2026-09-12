<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Masuk memakai akun Core yang sama.
 *
 * Konsol ini tidak punya jalur pendaftaran, undangan, maupun setel ulang kata sandi — semuanya
 * tetap di Core dan tidak disentuh. Selama keputusan tentang letak identitas belum diambil,
 * menaruh salinan kedua dari jalur-jalur itu di sini hanya menambah tempat yang bisa salah.
 */
class Login extends Controller
{
    public function form(): InertiaResponse
    {
        return Inertia::render('login');
    }

    public function submit(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($input, $request->boolean('remember'))) {
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
}
