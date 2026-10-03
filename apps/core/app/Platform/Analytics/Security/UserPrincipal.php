<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Access\Support\DataPolicyAccessResolver;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;

/**
 * Pengguna tenant yang menjalankan query dari layar, atas keanggotaannya sendiri.
 *
 * Hak dan hibahnya dibaca dari rantai yang sama dengan layar module: permission lewat
 * `LaunchableAppCatalog::permissionsFor()` (role → duty → privilege → permission) dan hibah kebijakan
 * data lewat `DataPolicyAccessResolver::resolve()`. Dasbor bersama kelak dihitung dengan principal
 * **yang melihat**, bukan pembuatnya, jadi principal ini selalu dibuat dari keanggotaan sesi.
 */
final class UserPrincipal implements AnalyticsPrincipal
{
    /** @var array<string, list<string>> permission per module, dibaca sekali per principal */
    private array $permissions = [];

    public function __construct(
        private readonly TenantMembership $membership,
        private readonly string $timezone,
        private readonly LaunchableAppCatalog $apps,
        private readonly DataPolicyAccessResolver $policies,
    ) {}

    /**
     * Principal dari keanggotaan sesi. Zonanya dari `UserClock`, layanan yang sama dengan yang dipakai
     * laporan (`ReportSource::forModule()`): zona My Profile, lalu zona entitas legal aktif.
     */
    public static function fromMembership(TenantMembership $membership, string $timezone): self
    {
        return new self($membership, $timezone, app(LaunchableAppCatalog::class), app(DataPolicyAccessResolver::class));
    }

    public function tenantId(): string
    {
        return (string) $this->membership->tenant_id;
    }

    public function holdsPermission(string $moduleId, string $permission): bool
    {
        $this->permissions[$moduleId] ??= $this->apps->permissionsFor($this->membership, $moduleId);

        return in_array($permission, $this->permissions[$moduleId], true);
    }

    public function policyScope(string $policyCode): array
    {
        return $this->policies->resolve($this->membership)[$policyCode] ?? ['all' => false, 'scope_grants' => []];
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
        return config()->integer('analytics.limits.rows_interactive', 5000);
    }

    public function timeoutMs(): int
    {
        return config()->integer('analytics.timeouts.interactive_ms', 8000);
    }

    public function describe(): string
    {
        return 'membership:'.$this->membership->id;
    }
}
