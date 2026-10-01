<?php

declare(strict_types=1);

namespace App\Platform\ChangeLog\Support;

use App\Platform\Modules\Contracts\ChangeLogValueResolver;
use App\Platform\Modules\Contracts\ChangeLogValueResolvers;

/**
 * Penerjemah nilai log perubahan yang terdaftar di proses ini, berkunci nama tabel.
 *
 * Diikat sebagai satu benda (`CoreServices::PEMETAAN_TUNGGAL`), supaya pendaftaran dari penyedia layanan
 * module dan pembacaan riwayat memegang daftar yang sama.
 */
final class ChangeLogValueResolverRegistry implements ChangeLogValueResolvers
{
    /** @var array<string, ChangeLogValueResolver> */
    private array $resolvers = [];

    public function register(ChangeLogValueResolver $resolver): void
    {
        $this->resolvers[$resolver->table()] = $resolver;
    }

    public function for(string $table): ?ChangeLogValueResolver
    {
        return $this->resolvers[$table] ?? null;
    }
}
