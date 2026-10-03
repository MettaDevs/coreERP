<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Analytics\Datasets\CompiledDataset;
use Carbon\CarbonImmutable;

/**
 * Pihak yang menjalankan query. Setiap jalur masuk membuat principal-nya sendiri; mesin query hanya
 * mengenal antarmuka ini, jadi tidak ada cabang "kalau dari API, lewati …".
 *
 * Bentuknya di `docs/todo/analitik/keamanan.md` bagian *Principal*. Area 0 mengirim subsetnya; area 4
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

    /**
     * Boleh memakai field data pribadi (`EndUserIdentifiableInformation`) dan melihat nama orang di balik
     * id pengguna atau pekerja. Publikasi dan embed tidak pernah boleh (KA-05).
     */
    public function mayUsePersonalData(): bool;

    /**
     * Saringan yang tidak dapat dilepas principal ini untuk satu dataset (publikasi, embed), bentuknya sama
     * dengan `filters` query: kunci field => ekspresi filter atau daftar pilihan. Kosong untuk pengguna.
     *
     * Saringan terkunci yang nilainya kosong berarti **nol baris**, tidak pernah "semua"
     * ({@see DataPolicyScope}).
     *
     * @return array<string, string|list<string>>
     */
    public function lockedFilters(string $dataset): array;

    /** Zona waktu IANA principal, untuk "hari ini", saringan tanggal-jam, dan cap waktu hasil. */
    public function timezone(): string;

    public function now(): CarbonImmutable;

    /** Batas baris hasil kelompok bila query tidak menyebut `limit`, sekaligus batas tertinggi `limit`. */
    public function rowLimit(): int;

    /** `statement_timeout` dalam milidetik. */
    public function timeoutMs(): int;

    /**
     * Sidik jari jangkauan principal atas satu dataset, untuk kunci cache: dua principal dengan sidik jari
     * sama pasti melihat baris yang sama. Hitungannya {@see ScopeFingerprint}.
     */
    public function fingerprint(CompiledDataset $dataset): string;

    /** Untuk log: `membership:…`, `publication:…`, `embed:…`. */
    public function describe(): string;
}
