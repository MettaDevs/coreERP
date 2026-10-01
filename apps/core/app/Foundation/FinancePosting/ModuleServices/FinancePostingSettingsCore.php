<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting\ModuleServices;

use App\Foundation\FinancePosting\Support\PostingSettings;
use App\Platform\Modules\Contracts\FinancePostingSettings;
use App\Platform\Organization\Models\LegalEntity;
use RuntimeException;

/**
 * Meneruskan pertanyaan setelan posting dari module ke Core, di proses yang sama.
 *
 * Yang ditambahkan pembungkus ini hanya satu: menolak id entitas legal yang tidak ada. Tanpa itu,
 * id salah ketik menjawab `direct_payable` dan cutover kosong — jawaban yang sah untuk entitas yang
 * belum disetel, sehingga kesalahannya tidak pernah terlihat.
 */
final class FinancePostingSettingsCore implements FinancePostingSettings
{
    public function __construct(private readonly PostingSettings $settings) {}

    public function settlementMode(string $legalEntityId, string $date): string
    {
        $this->ensureExists($legalEntityId);

        return $this->settings->settlementMode($legalEntityId, $date);
    }

    public function cutover(string $legalEntityId): ?string
    {
        $this->ensureExists($legalEntityId);

        return $this->settings->cutover($legalEntityId);
    }

    private function ensureExists(string $legalEntityId): void
    {
        if (! LegalEntity::query()->whereKey($legalEntityId)->exists()) {
            throw new RuntimeException(sprintf('Entitas legal %s tidak ditemukan.', $legalEntityId));
        }
    }
}
