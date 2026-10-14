<?php

declare(strict_types=1);

namespace App\Foundation\NumberSequence\ModuleServices;

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Models\TenantNumberSequence;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;

/**
 * Meneruskan penerbitan nomor ke layanan Core, di proses yang sama.
 *
 * Tidak ada penerjemahan yang perlu dilakukan di sini: layanan Core memang sudah menerima
 * konteks berupa id, bukan objek. Pembungkusnya tetap ada supaya module bergantung pada
 * antarmuka, bukan pada kelas Core yang bebas berubah bentuk.
 */
final class NumberSequenceIssuerCore implements NumberSequenceIssuer
{
    public function __construct(private readonly NumberSequenceService $service) {}

    public function allowsManual(string $tenantId, string $appId, string $referenceCode): bool
    {
        return TenantNumberSequence::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('reference', fn ($query) => $query->where('app_id', $appId)->where('code', $referenceCode))
            ->where('status', 'active')
            ->where('is_continuous', false)
            ->where('allow_manual', true)
            ->exists();
    }

    public function issue(array $context, string $referenceCode, string $idempotencyKey, ?string $manualValue = null): array
    {
        return $this->service->issue($context, $referenceCode, $idempotencyKey, $manualValue);
    }

    public function allowsAutomatic(string $tenantId, string $appId, string $referenceCode): bool
    {
        return TenantNumberSequence::query()->where('tenant_id', $tenantId)
            ->whereHas('reference', fn ($query) => $query->where('app_id', $appId)->where('code', $referenceCode))
            ->where('status', 'active')->where('allow_automatic', true)->exists();
    }

    public function contextFields(string $tenantId, string $appId, string $referenceCode): array
    {
        $sequence = TenantNumberSequence::query()->where('tenant_id', $tenantId)
            ->whereHas('reference', fn ($query) => $query->where('app_id', $appId)->where('code', $referenceCode))->sharedLock()->first();

        return array_values(array_unique(array_column(array_filter($sequence->segments ?? [], fn (array $segment): bool => $segment['type'] === 'context'), 'value')));
    }

    public function reserve(array $context, string $referenceCode, string $idempotencyKey): array
    {
        return $this->service->reserve($context, $referenceCode, $idempotencyKey);
    }
}
