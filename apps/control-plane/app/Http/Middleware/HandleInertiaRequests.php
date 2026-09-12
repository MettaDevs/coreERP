<?php

declare(strict_types=1);

namespace ControlPlane\Http\Middleware;

use ControlPlane\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'operator' => $user instanceof User
                ? ['name' => $user->name, 'email' => $user->email]
                : null,
            'message' => fn (): ?string => $request->session()->get('message'),
        ];
    }
}
