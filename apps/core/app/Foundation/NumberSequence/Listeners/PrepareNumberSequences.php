<?php

declare(strict_types=1);

namespace App\Foundation\NumberSequence\Listeners;

use App\Foundation\NumberSequence\Actions\EnsureNumberSequenceDrafts;
use App\Foundation\NumberSequence\Models\NumberSequenceReference;
use App\Platform\Modules\Events\AppCatalogRegistered;
use App\Platform\Modules\Events\AppNumberSequenceReferencesDeclared;
use App\Platform\Modules\Events\ModuleInstallationRecorded;
use App\Platform\Tenant\Events\TenantModulesInstalled;

/**
 * Number sequence menjawab kejadian Platform: tenant lahir, module terpasang, katalog app terdaftar.
 *
 * Platform hanya memberi tahu; isi penyiapannya milik Foundation. Semua listener di sini sinkron dan
 * ikut transaksi pengirimnya, jadi kegagalannya membatalkan langkah Platform yang memicunya.
 */
final class PrepareNumberSequences
{
    public function __construct(private readonly EnsureNumberSequenceDrafts $drafts) {}

    public function forInstalledModule(ModuleInstallationRecorded $event): void
    {
        $this->drafts->forTenantAndApp($event->tenantId, $event->moduleId);
    }

    public function forNewTenant(TenantModulesInstalled $event): void
    {
        $this->drafts->forReadyTenant($event->tenantId);
    }

    public function registerReferences(AppNumberSequenceReferencesDeclared $event): void
    {
        foreach ($event->references as $referenceData) {
            NumberSequenceReference::query()->updateOrCreate(['code' => $referenceData['code']], [
                'app_id' => $event->appId,
                'name' => $referenceData['name'],
                'default_prefix' => $referenceData['default_prefix'] ?? null,
                'allowed_scopes' => $referenceData['allowed_scopes'],
            ]);
        }
    }

    public function forRegisteredApp(AppCatalogRegistered $event): void
    {
        $this->drafts->forReadyApp($event->appId);
    }
}
