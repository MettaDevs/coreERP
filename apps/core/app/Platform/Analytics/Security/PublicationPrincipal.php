<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Access\Support\DataPolicyAccessResolver;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;

/**
 * Publikasi yang dibaca dari luar CoreERP (`docs/todo/analitik/keamanan.md` bagian *Principal*): dihitung sebagai
 * **pemilik publikasi saat ini**, dipersempit saringan terkunci publikasi, tanpa data pribadi (KA-05).
 *
 * Klien integrasi bukan anggota tenant dan tidak punya peran maupun hibah (KA-11), jadi tidak ada yang dipinjam
 * darinya. Permission module dan hibah kebijakan data dibaca dari keanggotaan pemilik lewat rantai yang sama
 * dengan layar ({@see UserPrincipal}); karena itu jangkauan publikasi tidak pernah lebih luas daripada yang
 * dapat dibuka pemiliknya sendiri hari ini. Principal ini dibuat {@see PublicationAccess::principal()} pada
 * setiap permintaan, sesudah keanggotaan dan hak publikasi pemiliknya diperiksa ulang — tidak pernah disimpan.
 *
 * Saringan terkunci berbentuk sama dengan `filters` query dan dipasang {@see DataPolicyScope} sebelum saringan
 * pemanggil; saringan yang nilainya kosong berarti nol baris. Zona waktunya milik publikasi, bukan milik server.
 * Batas baris dan waktu dari `analytics.publications.*`.
 */
final class PublicationPrincipal implements AnalyticsPrincipal
{
    /** @var array<string, list<string>> permission per module, dibaca sekali per principal */
    private array $permissions = [];

    public function __construct(
        private readonly Publication $publication,
        private readonly TenantMembership $owner,
        private readonly ?string $clientId,
        private readonly LaunchableAppCatalog $apps,
        private readonly DataPolicyAccessResolver $policies,
    ) {}

    /**
     * `$clientId` adalah klien integrasi yang membaca, untuk log; kosong saat pemiliknya sendiri memeriksa
     * publikasi dari layar.
     */
    public static function make(Publication $publication, TenantMembership $owner, ?string $clientId = null): self
    {
        return new self($publication, $owner, $clientId, app(LaunchableAppCatalog::class), app(DataPolicyAccessResolver::class));
    }

    public function tenantId(): string
    {
        return $this->publication->tenant_id;
    }

    public function holdsPermission(string $moduleId, string $permission): bool
    {
        $this->permissions[$moduleId] ??= $this->apps->permissionsFor($this->owner, $moduleId);

        return in_array($permission, $this->permissions[$moduleId], true);
    }

    public function policyScope(string $policyCode): array
    {
        return $this->policies->resolve($this->owner)[$policyCode] ?? ['all' => false, 'scope_grants' => []];
    }

    public function mayUsePersonalData(): bool
    {
        return false;
    }

    public function lockedFilters(string $dataset): array
    {
        return $this->publication->lockedFiltersFor($dataset);
    }

    public function timezone(): string
    {
        return $this->publication->timezone;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->publication->timezone);
    }

    public function rowLimit(): int
    {
        return config()->integer('analytics.publications.rows_max', 20000);
    }

    public function timeoutMs(): int
    {
        return config()->integer('analytics.publications.timeout_ms', 15000);
    }

    public function fingerprint(CompiledDataset $dataset): string
    {
        return ScopeFingerprint::of($this, $dataset->code, $dataset->policy['code'] ?? null);
    }

    /** `publication:<id>` dan, bila dibaca klien integrasi, `;client:<id>` — dicatat log query (area 9). */
    public function describe(): string
    {
        $describe = 'publication:'.($this->publication->exists ? $this->publication->id : 'baru');

        return $this->clientId === null ? $describe : $describe.';client:'.$this->clientId;
    }
}
