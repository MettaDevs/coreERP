<?php

declare(strict_types=1);

namespace App\Platform\Access\Support;

use App\Platform\Modules\Contracts\LinkedWorkerResolver;
use App\Platform\Modules\Contracts\LinkedWorkerResolvers;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Modules\Models\ModuleInstallation;

/**
 * Penjawab pekerja tertaut yang terdaftar di proses ini, berkunci id module.
 *
 * Diikat sebagai satu benda (`CoreServices::SINGLETON_BINDINGS`). Yang ditanya hanya module yang terpasang
 * untuk tenant itu menurut `core_module_installations`: pada tenant berdatabase sendiri, tabel module yang
 * belum dipasang memang belum ada. Pertanyaannya dijalankan dengan tenant itu sebagai tenant aktif, supaya
 * penyaringan tenant model module berlaku di luar rute module.
 */
final class LinkedWorkerResolverRegistry implements LinkedWorkerResolvers
{
    /** @var array<string, LinkedWorkerResolver> */
    private array $resolvers = [];

    public function __construct(private readonly TenantRunner $runner) {}

    public function register(LinkedWorkerResolver $resolver): void
    {
        $this->resolvers[$resolver->moduleId()] = $resolver;
    }

    public function availableFor(string $tenantId): bool
    {
        return $this->installedResolvers($tenantId) !== [];
    }

    public function forMemberships(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $workers = [];
        foreach ($this->installedResolvers($tenantId) as $resolver) {
            // Module yang terdaftar lebih dulu menang bila dua module menautkan keanggotaan yang sama.
            $workers += $this->runner->runFor($tenantId, fn (): array => $resolver->forMemberships($tenantId, $membershipIds));
        }

        return $workers;
    }

    /** @return list<LinkedWorkerResolver> */
    private function installedResolvers(string $tenantId): array
    {
        if ($this->resolvers === []) {
            return [];
        }

        $installed = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ModuleInstallation::STATUS_INSTALLED)
            ->whereIn('module_id', array_keys($this->resolvers))
            ->pluck('module_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_intersect_key($this->resolvers, array_flip($installed)));
    }
}
