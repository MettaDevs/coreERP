<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Access\Support\CorePermissions;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Access\Support\DataPolicyAccessResolver;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Pengguna tenant yang menjalankan query dari layar, atas keanggotaannya sendiri.
 *
 * Hak dan hibahnya dibaca dari rantai yang sama dengan layar module: permission lewat
 * `LaunchableAppCatalog::permissionsFor()` (role → duty → privilege → permission) dan hibah kebijakan
 * data lewat `DataPolicyAccessResolver::resolve()`. Dasbor bersama kelak dihitung dengan principal
 * **yang melihat**, bukan pembuatnya, jadi principal ini selalu dibuat dari keanggotaan sesi.
 *
 * Hak data pribadi adalah permission Core `core.analytics.personal-data.read` (duty *Pakai data pribadi di
 * analitik*), dibaca lewat `CorePermissions` yang sama dengan gate rute, sehingga tidak menambah query.
 * Pengguna tidak pernah punya saringan terkunci; batas baris dan waktu dari `config/analytics.php`.
 *
 * Perusahaan workspace sesi ({@see self::workspaceLegalEntity()}) dibaca hanya bila dibutuhkan — token tahun fiskal
 * tanpa saringan perusahaan (area 13) — dan hanya dari permintaan yang punya sesi; perintah artisan dan job tidak
 * punya workspace.
 */
final class UserPrincipal implements AnalyticsPrincipal
{
    /** @var array<string, list<string>> permission per module, dibaca sekali per principal */
    private array $permissions = [];

    /** Perusahaan workspace, dibaca sekali saat pertama dibutuhkan; `false` berarti belum dibaca. */
    private string|false|null $workspaceLegalEntity = false;

    public function __construct(
        private readonly TenantMembership $membership,
        private readonly string $timezone,
        private readonly LaunchableAppCatalog $apps,
        private readonly DataPolicyAccessResolver $policies,
        private readonly CorePermissions $corePermissions,
    ) {}

    /**
     * Principal dari keanggotaan sesi. Zonanya dari `UserClock`, layanan yang sama dengan yang dipakai
     * laporan (`ReportSource::forModule()`): zona My Profile, lalu zona entitas legal aktif.
     */
    public static function fromMembership(TenantMembership $membership, string $timezone): self
    {
        return new self($membership, $timezone, app(LaunchableAppCatalog::class), app(DataPolicyAccessResolver::class), app(CorePermissions::class));
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

    public function mayUsePersonalData(): bool
    {
        return $this->corePermissions->allows($this->membership, CoreSecurityCatalog::ANALYTICS_PERSONAL_DATA_READ);
    }

    public function lockedFilters(string $dataset): array
    {
        return [];
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

    public function fingerprint(CompiledDataset $dataset): string
    {
        return ScopeFingerprint::of($this, $dataset->code, $dataset->policy['code'] ?? null);
    }

    public function describe(): string
    {
        return 'membership:'.$this->membership->id;
    }

    /**
     * Id perusahaan (entitas legal) yang dipilih pengguna di workspace sesi ini, atau null tanpa sesi (perintah
     * artisan, job) atau tanpa perusahaan yang dapat dipilih. Pilihan workspace dibaca lewat `CurrentWorkspace`,
     * yang sama dengan layar module dan zona waktu pengguna.
     */
    public function workspaceLegalEntity(): ?string
    {
        if ($this->workspaceLegalEntity === false) {
            $request = app('request');
            $this->workspaceLegalEntity = $request instanceof Request && $request->hasSession()
                ? app(CurrentWorkspace::class)->legalEntity($request, $this->membership)?->id
                : null;
        }

        return $this->workspaceLegalEntity;
    }
}
