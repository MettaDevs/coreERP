<?php

declare(strict_types=1);

namespace App\Support\Sso;

use RuntimeException;

/**
 * Upacara masuk lewat SSO berhenti, dengan kode yang boleh ditampilkan ke orangnya.
 *
 * Kodenya sempit dan tetap, bukan pesan bebas. Kegagalan di alamat balik bersama dikembalikan ke
 * halaman masuk tenant lewat query string, dan halaman yang mencetak teks apa pun dari query string
 * adalah halaman yang dapat dipakai orang lain untuk menulis pesan palsu atas nama kita.
 */
final class SsoFailure extends RuntimeException
{
    public const PROVIDER_UNREACHABLE = 'penyedia-tidak-terjangkau';

    public const REJECTED_BY_PROVIDER = 'ditolak-penyedia';

    public const INVALID_TOKEN = 'token-tidak-sah';

    /** Akun SSO ini belum pernah dihubungkan ke akun CoreERP mana pun. */
    public const NOT_LINKED = 'belum-terhubung';

    /** Akun SSO ini sudah terhubung ke akun CoreERP lain, atau akun ini sudah terhubung ke akun SSO lain. */
    public const LINKED_ELSEWHERE = 'terhubung-ke-akun-lain';

    public const NOT_A_MEMBER = 'bukan-anggota';

    public const EXPIRED = 'kedaluwarsa';

    /** @var list<string> */
    public const CODES = [
        self::PROVIDER_UNREACHABLE,
        self::REJECTED_BY_PROVIDER,
        self::INVALID_TOKEN,
        self::NOT_LINKED,
        self::LINKED_ELSEWHERE,
        self::NOT_A_MEMBER,
        self::EXPIRED,
    ];

    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }

    /** Kalimat untuk orangnya. Null bila kodenya tidak dikenal — dan kode asing tidak ditampilkan. */
    public static function messageFor(mixed $code): ?string
    {
        return match ($code) {
            self::PROVIDER_UNREACHABLE => 'Penyedia SSO sedang tidak dapat dihubungi. Coba lagi sebentar lagi, atau masuk dengan kata sandi.',
            self::REJECTED_BY_PROVIDER => 'Penyedia SSO tidak mengizinkan masuk ke aplikasi ini.',
            self::INVALID_TOKEN => 'Tanda masuk dari penyedia SSO tidak dapat diverifikasi.',
            self::NOT_LINKED => 'Akun SSO ini belum terhubung ke akun CoreERP. Masuk dengan kata sandi, lalu hubungkan SSO di Pengaturan > Keamanan.',
            self::LINKED_ELSEWHERE => 'Akun SSO ini sudah terhubung ke akun CoreERP lain, atau akun Anda sudah terhubung ke akun SSO yang berbeda.',
            self::NOT_A_MEMBER => 'Akun ini bukan anggota aktif tenant ini.',
            self::EXPIRED => 'Upacara masuk sudah kedaluwarsa. Tekan tombol masuk lewat SSO sekali lagi.',
            default => null,
        };
    }
}
