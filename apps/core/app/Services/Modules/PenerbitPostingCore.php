<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\Finance\PostingPublisher;
use App\Support\Modules\Contracts\PenerbitPosting;

/**
 * Pintu module ke penerbit posting Core. Seluruh aturannya tinggal di `PostingPublisher`, yang juga
 * dipakai penilaian ulang dan layar pantau, supaya pratinjau di modul dan posting yang benar-benar
 * terbit tidak pernah diperiksa dengan cara berbeda.
 */
final class PenerbitPostingCore implements PenerbitPosting
{
    public function __construct(private readonly PostingPublisher $penerbit) {}

    public function terbitkan(array $posting): array
    {
        return $this->penerbit->publish($posting);
    }

    public function pratinjau(array $posting): array
    {
        return $this->penerbit->preview($posting);
    }

    public function status(string $tenantId, string $postingId): ?array
    {
        return $this->penerbit->status($tenantId, $postingId);
    }
}
