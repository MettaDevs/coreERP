<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use Carbon\CarbonImmutable;

/**
 * Pihak yang menjalankan query. Setiap jalur masuk membuat principal-nya sendiri; mesin query hanya
 * mengenal antarmuka ini, jadi tidak ada cabang "kalau dari API, lewati …".
 *
 * Isi kerangka berjalan (area 0) adalah subset antarmuka di `docs/todo/analitik/keamanan.md`. Area 4
 * menambahkan `mayUsePersonalData()`, `lockedFilters()`, dan `fingerprint()` tanpa mengubah method yang
 * sudah ada.
 */
interface AnalyticsPrincipal
{
    /** Tenant yang dibaca. Selalu dari konteks tepercaya (sesi, klien integrasi, token), tidak pernah dari body. */
    public function tenantId(): string;

    /** Permission baca module yang dipegang, untuk dataset module itu. */
    public function holdsPermission(string $moduleId, string $permission): bool;

    /**
     * Hibah satu kebijakan data, bentuk `DataPolicyAccessResolver::resolve()`. Kebijakan tanpa hibah
     * memulangkan `['all' => false, 'scope_grants' => []]`, bukan null.
     *
     * @return array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}
     */
    public function policyScope(string $policyCode): array;

    /** Zona waktu IANA principal, untuk "hari ini", saringan tanggal-jam, dan cap waktu hasil. */
    public function timezone(): string;

    public function now(): CarbonImmutable;

    /** Batas baris hasil kelompok bila query tidak menyebut `limit`, sekaligus batas tertinggi `limit`. */
    public function rowLimit(): int;

    /** `statement_timeout` dalam milidetik. */
    public function timeoutMs(): int;

    /** Untuk log: `membership:…`, `publication:…`, `embed:…`. */
    public function describe(): string;
}
