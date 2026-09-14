<?php

declare(strict_types=1);

namespace ControlPlane\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu upacara OIDC konsol, dari tombol sampai alamat balik — disimpan di sesi.
 *
 * ## Kenapa sesi cukup di sini, padahal Core memakai tabel
 *
 * Konsol punya satu alamat, dan alamat balik yang terdaftar di penyedia berada di alamat itu juga.
 * Cookie sesi yang dibawa peramban saat kembali adalah cookie yang sama dengan saat tombolnya
 * ditekan. `state` yang dicocokkan dari sesi karena itu sekaligus membuktikan bahwa peramban yang
 * kembali adalah peramban yang memulai — pembela login CSRF yang di Core harus dibangun terpisah
 * dengan cookie karena upacaranya menyeberangi dua alamat.
 *
 * Upacara yang dikirim penyerang ke peramban korban tidak menemukan `state`-nya di sesi korban,
 * dan berhenti di sana.
 */
final class Ceremony
{
    private const SESSION_KEY = 'sso.ceremony';

    private const MINUTES = 10;

    public const LOGIN = 'masuk';

    public const CONNECT = 'hubungkan';

    public function __construct(private readonly IdentityProvider $provider) {}

    /** Memulai upacara dan memulangkan jawaban yang mengirim peramban ke penyedia. */
    public function begin(Request $request, string $mode, ?int $userId = null): Response
    {
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirectUri = $request->getSchemeAndHttpHost().'/sso/callback';

        $request->session()->put(self::SESSION_KEY, [
            'state_hash' => hash('sha256', $state),
            'nonce' => $nonce,
            'verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'mode' => $mode,
            'user_id' => $userId,
            'expires_at' => now()->addMinutes(self::MINUTES)->getTimestamp(),
        ]);

        $url = $this->provider->authorizationUrl($state, $nonce, $challenge, $redirectUri);

        // Tombol "Hubungkan" dikirim lewat Inertia (XHR), yang tidak dapat mengikuti pengalihan ke
        // domain penyedia. `Inertia::location` menyuruh peramban berpindah halaman sendiri.
        return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }

    /**
     * Mengambil upacara yang cocok dengan `state` di alamat balik, lalu membuangnya dari sesi.
     *
     * Dibuang apa pun hasilnya: satu `state` satu kali pakai.
     *
     * @return array{nonce: string, verifier: string, redirect_uri: string, mode: string, user_id: ?int}|null
     */
    public function pull(Request $request, mixed $state): ?array
    {
        $ceremony = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($ceremony) || ! is_string($state) || $state === '') {
            return null;
        }

        if (! hash_equals((string) ($ceremony['state_hash'] ?? ''), hash('sha256', $state))) {
            return null;
        }

        if ((int) ($ceremony['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return [
            'nonce' => (string) $ceremony['nonce'],
            'verifier' => (string) $ceremony['verifier'],
            'redirect_uri' => (string) $ceremony['redirect_uri'],
            'mode' => (string) $ceremony['mode'],
            'user_id' => isset($ceremony['user_id']) ? (int) $ceremony['user_id'] : null,
        ];
    }
}
