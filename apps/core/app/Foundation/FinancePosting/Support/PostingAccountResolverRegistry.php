<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting\Support;

use App\Platform\Modules\Contracts\PostingAccountResolver;
use App\Platform\Modules\Contracts\PostingAccountResolvers;

/**
 * Pemeta akun module yang terdaftar di proses ini, berkunci id module.
 *
 * Diikat sebagai satu benda (`FinancePostingServiceProvider`), supaya pendaftaran dari penyedia
 * layanan module dan pembacaan oleh penerbit posting memegang daftar yang sama.
 */
final class PostingAccountResolverRegistry implements PostingAccountResolvers
{
    /** @var array<string, PostingAccountResolver> */
    private array $resolvers = [];

    public function register(PostingAccountResolver $resolver): void
    {
        $this->resolvers[$resolver->moduleId()] = $resolver;
    }

    public function for(string $moduleId): ?PostingAccountResolver
    {
        return $this->resolvers[$moduleId] ?? null;
    }
}
