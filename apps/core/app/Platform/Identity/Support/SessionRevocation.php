<?php

declare(strict_types=1);

namespace App\Platform\Identity\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/** Pencabutan seluruh sesi pengguna lintas host, terpisah dari cache yang dapat dibersihkan. */
final class SessionRevocation
{
    private const SESSION_KEY = 'auth.session_generation';

    public function enabled(): bool
    {
        return config('session.driver') === 'redis';
    }

    public function stamp(Request $request, int|string $userId): void
    {
        if ($this->enabled() && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $this->generation($userId));
        }
    }

    public function revoke(int|string $userId): void
    {
        // Tidak diberi TTL: habisnya penanda tidak boleh mengubah generasi sesi yang masih aktif.
        if (! Cache::store('sessions')->forever($this->key($userId), (string) Str::uuid())) {
            throw new RuntimeException('Pencabutan sesi belum tersimpan.');
        }
    }

    public function revoked(Request $request, int|string $userId): bool
    {
        return $this->enabled()
            && $request->session()->get(self::SESSION_KEY, '0') !== $this->generation($userId);
    }

    private function generation(int|string $userId): string
    {
        return (string) Cache::store('sessions')->get($this->key($userId), '0');
    }

    private function key(int|string $userId): string
    {
        return 'auth:session-generation:'.$userId;
    }
}
