<?php

declare(strict_types=1);

namespace ControlPlane\Sso;

use RuntimeException;

/**
 * Upacara masuk lewat SSO berhenti, dengan kode yang boleh ditampilkan ke operator.
 *
 * Kodenya sama dengan milik Core untuk kegagalan yang sama, ditambah satu yang hanya ada di konsol:
 * akun yang sah dan terhubung tetapi bukan operator. Kalimatnya dipetakan dari kode tetap — teks
 * bebas dari alamat tidak pernah dicetak.
 */
final class SsoFailure extends RuntimeException
{
    public const PROVIDER_UNREACHABLE = 'penyedia-tidak-terjangkau';

    public const REJECTED_BY_PROVIDER = 'ditolak-penyedia';

    public const INVALID_TOKEN = 'token-tidak-sah';

    public const NOT_LINKED = 'belum-terhubung';

    public const LINKED_ELSEWHERE = 'terhubung-ke-akun-lain';

    public const NOT_AN_OPERATOR = 'bukan-operator';

    public const EXPIRED = 'kedaluwarsa';

    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }

    public static function messageFor(mixed $code): ?string
    {
        return match ($code) {
            self::PROVIDER_UNREACHABLE => 'Penyedia SSO sedang tidak dapat dihubungi. Coba lagi sebentar lagi.',
            self::REJECTED_BY_PROVIDER => 'Penyedia SSO tidak mengizinkan masuk ke konsol ini.',
            self::INVALID_TOKEN => 'Tanda masuk dari penyedia SSO tidak dapat diverifikasi.',
            self::NOT_LINKED => 'Akun SSO ini belum terhubung ke akun konsol. Masuk dengan kata sandi, lalu hubungkan SSO di halaman Akun.',
            self::LINKED_ELSEWHERE => 'Akun SSO ini sudah terhubung ke akun lain, atau akun Anda sudah terhubung ke akun SSO yang berbeda.',
            self::NOT_AN_OPERATOR => 'Akun ini bukan operator Pusat Admin.',
            self::EXPIRED => 'Upacara masuk sudah kedaluwarsa atau tidak dikenal. Coba sekali lagi.',
            default => null,
        };
    }
}
