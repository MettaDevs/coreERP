<?php

declare(strict_types=1);

namespace App\Support\Sso;

/**
 * Satu pengguna sebagaimana dikenal penyedia SSO.
 *
 * `subject` adalah nilai yang kelak muncul sebagai klaim `sub` di ID token, dan dijadikan string di
 * sini — satu kali, di batas — supaya perbandingannya dengan `sso_subject` tidak pernah bergantung
 * pada apakah penyedia mengirimnya sebagai angka atau teks pada hari itu.
 */
final readonly class SsoDirectoryUser
{
    public function __construct(
        public string $subject,
        public string $name,
        public string $email,
        public bool $isActive,
    ) {}
}
