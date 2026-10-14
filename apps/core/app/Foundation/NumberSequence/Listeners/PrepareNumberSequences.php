<?php

declare(strict_types=1);

namespace App\Foundation\NumberSequence\Listeners;

use App\Foundation\NumberSequence\Actions\EnsureNumberSequenceDrafts;
use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Models\NumberSequenceReference;
use App\Platform\Modules\Events\AppCatalogRegistered;
use App\Platform\Modules\Events\AppNumberSequenceReferencesDeclared;
use App\Platform\Modules\Events\ModuleInstallationRecorded;
use App\Platform\Tenant\Events\TenantModulesInstalled;
use Illuminate\Validation\ValidationException;

/**
 * Number sequence menjawab kejadian Platform: tenant lahir, module terpasang, katalog app terdaftar.
 *
 * Platform hanya memberi tahu; isi penyiapannya milik Foundation. Semua listener di sini sinkron dan
 * ikut transaksi pengirimnya, jadi kegagalannya membatalkan langkah Platform yang memicunya.
 */
final class PrepareNumberSequences
{
    public function __construct(private readonly EnsureNumberSequenceDrafts $drafts, private readonly NumberSequenceService $numbers) {}

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
            $defaults = $referenceData['default_settings'] ?? [];
            $segments = $defaults['segments'] ?? [['type' => 'number', 'length' => 5]];
            $scopes = $referenceData['allowed_scopes'];
            $scopeType = in_array('tenant', $scopes, true) ? 'tenant' : (in_array('legal_entity', $scopes, true) ? 'legal_entity' : 'operating_unit');
            $this->numbers->validateSegments($segments, $scopeType, 'never');
            $width = collect($segments)->firstWhere('type', 'number')['length'] ?? 0;
            if (($defaults['maximum_number'] ?? 19999) > (10 ** $width) - 1 || ($defaults['minimum_number'] ?? 1) > ($defaults['maximum_number'] ?? 19999)) {
                throw ValidationException::withMessages(['number_sequences.references' => 'Rentang nomor harus sesuai panjang bagian nomor urut.']);
            }
            NumberSequenceReference::query()->updateOrCreate(['code' => $referenceData['code']], [
                'app_id' => $event->appId,
                'name' => $referenceData['name'],
                'default_prefix' => $referenceData['default_prefix'] ?? null,
                'allowed_scopes' => $referenceData['allowed_scopes'],
                'default_settings' => $referenceData['default_settings'] ?? null,
            ]);
        }
    }

    public function forRegisteredApp(AppCatalogRegistered $event): void
    {
        $this->drafts->forReadyApp($event->appId);
    }
}
