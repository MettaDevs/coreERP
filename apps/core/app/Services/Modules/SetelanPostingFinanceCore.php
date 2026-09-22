<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\LegalEntity;
use App\Support\Finance\PostingSettings;
use App\Support\Modules\Contracts\SetelanPostingFinance;
use RuntimeException;

/**
 * Meneruskan pertanyaan setelan posting dari module ke Core, di proses yang sama.
 *
 * Yang ditambahkan pembungkus ini hanya satu: menolak id entitas legal yang tidak ada. Tanpa itu,
 * id salah ketik menjawab `direct_payable` dan cutover kosong — jawaban yang sah untuk entitas yang
 * belum disetel, sehingga kesalahannya tidak pernah terlihat.
 */
final class SetelanPostingFinanceCore implements SetelanPostingFinance
{
    public function __construct(private readonly PostingSettings $setelan) {}

    public function modePenyelesaian(string $legalEntityId, string $tanggal): string
    {
        $this->pastikanAda($legalEntityId);

        return $this->setelan->settlementMode($legalEntityId, $tanggal);
    }

    public function cutover(string $legalEntityId): ?string
    {
        $this->pastikanAda($legalEntityId);

        return $this->setelan->cutover($legalEntityId);
    }

    private function pastikanAda(string $legalEntityId): void
    {
        if (! LegalEntity::query()->whereKey($legalEntityId)->exists()) {
            throw new RuntimeException(sprintf('Entitas legal %s tidak ditemukan.', $legalEntityId));
        }
    }
}
