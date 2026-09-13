<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Customers;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Application;
use ControlPlane\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Semua pelanggan yang dikenal sistem, satu tabel — dan pintu melahirkan yang baru.
 *
 * Sebelum layar ini, tenant hanya lahir dari pendaftaran mandiri. Akibatnya dropdown "Pelanggan"
 * pada dialog buat lingkungan cuma memuat perusahaan yang kebetulan sudah mendaftar sendiri, yang
 * kebalikan dari alur jualan yang sebenarnya: perusahaan tertarik lebih dulu, lalu operator
 * membuatkan tempatnya.
 *
 * Penyaring dan halaman belum ada, sama seperti di layar Lingkungan. Keduanya menunggu jumlahnya
 * cukup banyak untuk membuat satu layar tidak terbaca lagi.
 */
class Index extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        return Inertia::render('customers/index', [
            'customers' => Tenant::forScreen(),
            'app' => Application::options(),
            'credentials' => $this->credentials($request),
        ]);
    }

    /**
     * Kata sandi sementara yang baru saja lahir, dibaca satu kali lalu habis.
     *
     * Ia datang lewat flash session dan bukan lewat kolom database, dan itu keputusan yang paling
     * menentukan di seluruh layar ini: kata sandi yang tersimpan adalah kata sandi yang dapat
     * dibaca lagi besok, oleh siapa pun yang membuka halaman ini. Yang dibayar — ia benar-benar
     * hilang kalau operator menutup tab sebelum menyalinnya — karena itu wajib diumumkan di layar,
     * bukan dipendam sebagai perilaku yang baru ketahuan saat pertama kali menggigit.
     *
     * @return array{tenant: string, name: string, email: string, password: string}|null
     */
    private function credentials(Request $request): ?array
    {
        $flash = $request->session()->get('credentials');

        if (! is_array($flash)) {
            return null;
        }

        $tenant = $flash['tenant'] ?? null;
        $name = $flash['name'] ?? null;
        $email = $flash['email'] ?? null;
        $password = $flash['password'] ?? null;

        if (! is_string($tenant) || ! is_string($name) || ! is_string($email) || ! is_string($password)) {
            return null;
        }

        return ['tenant' => $tenant, 'name' => $name, 'email' => $email, 'password' => $password];
    }
}
