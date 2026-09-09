<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Models\TenantMembership;
use App\Support\DataPolicyAccessResolver;
use App\Support\LaunchableAppCatalog;
use App\Support\Modules\Contracts\PenyediaLaporanModul;
use App\Support\Modules\PelaksanaTenant;
use App\Support\Reporting\Rendering\RenderException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Dari mana definisi, layout bawaan, dan dataset sebuah laporan diambil.
 *
 * Ada dua jalur dan hanya satu yang dipilih per laporan. Bila `app_id`-nya adalah module
 * yang berjalan di dalam proses ini, laporannya dibaca langsung lewat
 * {@see PenyediaLaporanModul}. Bila tidak, ia app di luar proses dan {@see AppReportClient}
 * memanggilnya lewat HTTP seperti sebelumnya.
 *
 * Tanda tangan ketiga metodenya sengaja sama persis dengan milik `AppReportClient`. Itu
 * membuat pemanggil di Core tidak perlu tahu jalur mana yang dipakai — dan lebih penting,
 * membuat jalur in-process tidak bisa diam-diam menerima parameter yang berbeda dari yang
 * dulu dibawa token.
 *
 * **Tenant aktif diikat di sini, bukan dititipkan ke module.** Model module menyaring lewat
 * `TenantScope`, yang gagal-menutup: tanpa ikatan ini setiap query module melempar. Ekspor
 * laporan berjalan di worker antrean yang tidak pernah melewati middleware konteks, jadi
 * tidak ada yang mengikatnya kalau bukan di sini. Ikatan sebelumnya dikembalikan setelah
 * panggilan selesai, karena satu worker menjalankan banyak ekspor milik tenant berbeda
 * berturut-turut di container yang sama.
 */
final class SumberLaporan
{
    public function __construct(
        private readonly DaftarLaporanModul $daftar,
        private readonly AppReportClient $klien,
        private readonly LaunchableAppCatalog $apps,
        private readonly DataPolicyAccessResolver $kebijakan,
        private readonly PelaksanaTenant $pelaksana,
    ) {}

    /**
     * @return array{fields: list<array{key: string, label: string, table: ?string}>, parameters: list<string>}
     */
    public function definition(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): array
    {
        $penyedia = $this->penyedia($report);

        if ($penyedia === null) {
            return $this->klien->definition($report, $membership, $legalEntityId, $orgUnitId);
        }

        return $this->jalankan($report, $membership, fn (array $konteks): array => $penyedia->definisi(
            $this->kodeLokal($report, $penyedia),
            $konteks,
        ), $legalEntityId, $orgUnitId);
    }

    public function builtinLayout(stdClass $report, string $key, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): string
    {
        $penyedia = $this->penyedia($report);

        if ($penyedia === null) {
            return $this->klien->builtinLayout($report, $key, $membership, $legalEntityId, $orgUnitId);
        }

        return $this->jalankan($report, $membership, fn (array $konteks): string => $penyedia->layoutBawaan(
            $this->kodeLokal($report, $penyedia),
            $key,
            $konteks,
        ), $legalEntityId, $orgUnitId);
    }

    /** @param array<string, mixed> $parameters */
    public function dataset(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, array $parameters): ReportData
    {
        $penyedia = $this->penyedia($report);

        if ($penyedia === null) {
            return $this->klien->dataset($report, $membership, $legalEntityId, $orgUnitId, $parameters);
        }

        $isi = $this->jalankan($report, $membership, fn (array $konteks): array => $penyedia->dataset(
            $this->kodeLokal($report, $penyedia),
            $konteks,
            $parameters,
        ), $legalEntityId, $orgUnitId);

        return ReportData::fromArray($isi);
    }

    private function penyedia(stdClass $report): ?PenyediaLaporanModul
    {
        $penyedia = $this->daftar->untuk((string) $report->app_id);

        if ($penyedia === null) {
            return null;
        }

        // Module terdaftar tapi tidak mengenal kode laporannya berarti katalog dan module
        // sudah tidak sepakat — biasanya karena manifest lebih baru daripada kode yang
        // terpasang. Jatuh ke HTTP di sini akan menyembunyikan itu di balik alamat yang
        // tidak ada; lebih baik gagal dengan sebabnya.
        if (! $penyedia->punya($this->kodeLokal($report, $penyedia))) {
            throw new RenderException(
                "Laporan `{$report->code}` terdaftar di katalog tetapi tidak dikenal module {$report->app_name}. ".
                'Manifest dan kode module tidak sepadan.'
            );
        }

        return $penyedia;
    }

    /** Kode laporan di sisi module: kode katalog tanpa awalan id module. */
    private function kodeLokal(stdClass $report, PenyediaLaporanModul $penyedia): string
    {
        return substr((string) $report->code, strlen($penyedia->idModule()) + 1);
    }

    /**
     * Menjalankan satu panggilan module dengan tenant aktif terikat dan konteks terisi.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $panggilan
     * @return T
     */
    private function jalankan(stdClass $report, TenantMembership $membership, callable $panggilan, ?string $legalEntityId, ?string $orgUnitId): mixed
    {
        $konteks = [
            'tenant_id' => (string) $membership->tenant_id,
            'legal_entity_id' => $legalEntityId,
            'org_unit_id' => $orgUnitId,
            'user_id' => (string) $membership->user_id,
            'permissions' => $this->apps->permissionsFor($membership, (string) $report->app_id),
            'data_policies' => $this->kebijakan->resolve($membership),
        ];

        try {
            return $this->pelaksana->jalankanUntuk(
                (string) $konteks['tenant_id'],
                static fn (): mixed => $panggilan($konteks),
            );
        } catch (RenderException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Module tidak boleh menyebut kelas pengecualian Core — batas namespace-nya satu
            // kalimat dan `RenderException` tidak ada di dalamnya. Jadi module melempar
            // pengecualian PHP biasa dengan pesan siap-baca, dan penerjemahannya di sini.
            throw new RenderException($e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new RenderException(
                "{$report->app_name} tidak dapat menyiapkan laporan ini: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
