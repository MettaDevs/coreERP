<?php

declare(strict_types=1);

namespace ControlPlane\Http\Middleware;

use ControlPlane\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class SiapkanInertia extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $pengguna = $request->user();

        return [
            ...parent::share($request),
            'operator' => $pengguna instanceof User
                ? ['nama' => $pengguna->name, 'email' => $pengguna->email]
                : null,
            'pesan' => fn (): ?string => $request->session()->get('pesan'),
        ];
    }
}
