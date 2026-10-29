<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Customers;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Application;
use ControlPlane\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
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
 * Daftar dibaca bertahap; kata sandi sementara tetap hanya berada pada respons halaman.
 */
class Index extends Controller
{
    public function __invoke(Request $request): InertiaResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            $input = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'cursor' => ['nullable', 'string', 'max:2048']]);
            $cursor = isset($input['cursor']) ? Cursor::fromEncoded($input['cursor']) : null;

            if (isset($input['cursor']) && ($cursor === null || ! is_string($cursor->toArray()['id'] ?? null))) {
                throw ValidationException::withMessages(['cursor' => 'Posisi daftar tidak valid. Muat ulang daftar.']);
            }

            $query = Tenant::query()->select(['id', 'name', 'created_at'])
                ->withCount(['environments as environments_count' => fn ($query) => $query->whereNull('deleted_at')]);
            if (($search = trim($input['search'] ?? '')) !== '') {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
            }
            $batch = $query->orderByDesc('id')->cursorPaginate(50, ['*'], 'cursor', $cursor);

            return response()->json([
                'data' => $batch->map(fn (Tenant $tenant): array => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'environments' => (int) $tenant->getAttribute('environments_count'),
                    'createdAt' => $tenant->created_at?->toDateString(),
                ])->all(),
                'next_cursor' => $batch->nextCursor()?->encode(),
            ]);
        }

        return Inertia::render('customers/index', [
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
