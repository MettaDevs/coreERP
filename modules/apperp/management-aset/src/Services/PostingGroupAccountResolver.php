<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\PostingAccountResolver;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;

/**
 * Akun posting group yang berlaku sekarang, untuk posting modul aset yang dibentuk ulang Core.
 *
 * Setiap baris jurnal aset membawa kunci `posting-group:<group>:<kolom>`. Saat posting yang tertahan
 * divalidasi ulang, Core menanyakan kunci itu ke sini dan memakai akun dari baris posting group yang
 * berlaku pada tanggal postingnya — sehingga mengisi kolom yang dulu kosong benar-benar melepas
 * postingnya, bukan hanya mengubah matriks.
 */
final class PostingGroupAccountResolver implements PostingAccountResolver
{
    private const PREFIX = 'posting-group';

    public function __construct(private readonly AssetPostingAccounts $accounts) {}

    public static function reference(string $groupAsetId, string $column): string
    {
        return self::PREFIX.':'.$groupAsetId.':'.$column;
    }

    public function moduleId(): string
    {
        return AcquisitionPosting::MODULE;
    }

    public function account(string $tenantId, string $reference, string $postingDate): ?string
    {
        $bagian = explode(':', $reference);
        if (count($bagian) !== 3 || $bagian[0] !== self::PREFIX || ! array_key_exists($bagian[2], AssetPostingGroup::ACCOUNTS)) {
            return null;
        }

        $akun = $this->accounts->effective($bagian[1], $postingDate)?->getAttribute($bagian[2]);

        return is_string($akun) ? $akun : null;
    }
}
