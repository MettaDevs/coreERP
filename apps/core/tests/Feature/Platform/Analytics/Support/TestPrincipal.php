<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics\Support;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\ScopeFingerprint;
use Carbon\CarbonImmutable;

/**
 * Principal bahan uji cache, batas, dan log analitik (area 9). Kelas sungguhan, bukan stub PHPUnit: stub
 * memulangkan sidik jari kosong untuk setiap principal, dan cache yang diuji dengannya akan berbagi hasil
 * di antara jangkauan yang berbeda tanpa ketahuan. Sidik jarinya dihitung `ScopeFingerprint` yang sama
 * dengan `UserPrincipal`.
 *
 * `now()` mengikuti jam test (`travel()`, `Carbon::setTestNow()`), dalam zona principal. `$fixedFingerprint`
 * hanya untuk test kunci cache yang perlu mengubah satu komponen kunci tanpa ikut mengubah sidik jari.
 */
final readonly class TestPrincipal implements AnalyticsPrincipal
{
    /**
     * @param  array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}  $scope
     * @param  array<string, string|list<string>>  $locked
     */
    public function __construct(
        private string $tenant,
        private array $scope = ['all' => true, 'scope_grants' => []],
        private bool $personalData = false,
        private string $timezone = 'Asia/Jakarta',
        private array $locked = [],
        private int $rowLimit = 5000,
        private int $timeoutMs = 3000,
        private bool $permitted = true,
        private string $name = 'uji',
        private ?string $fixedFingerprint = null,
    ) {}

    public function tenantId(): string
    {
        return $this->tenant;
    }

    public function holdsPermission(string $moduleId, string $permission): bool
    {
        return $this->permitted;
    }

    public function policyScope(string $policyCode): array
    {
        return $this->scope;
    }

    public function mayUsePersonalData(): bool
    {
        return $this->personalData;
    }

    public function lockedFilters(string $dataset): array
    {
        return $this->locked;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function rowLimit(): int
    {
        return $this->rowLimit;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function fingerprint(CompiledDataset $dataset): string
    {
        return $this->fixedFingerprint ?? ScopeFingerprint::of($this, $dataset->code, $dataset->policy['code'] ?? null);
    }

    public function describe(): string
    {
        return 'uji:'.$this->name;
    }
}
