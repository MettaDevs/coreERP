<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Platform\Identity\Support\UserClock;
use App\Platform\Tenant\Models\TenantMembership;
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
 * Hanya ada satu jalur: `app_id` sebuah laporan selalu module yang berjalan di dalam proses
 * ini, dan laporannya dibaca langsung lewat {@see PenyediaLaporanModul}. Jalur HTTP ke app di
 * luar proses dibuang bersama seluruh jalur hosting container; tidak ada lagi app yang
 * dilayaninya.
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
        private readonly LaunchableAppCatalog $apps,
        private readonly DataPolicyAccessResolver $kebijakan,
        private readonly PelaksanaTenant $pelaksana,
        private readonly ValueFormats $formats,
        private readonly UserClock $clock,
    ) {}

    /**
     * @return array{fields: list<array{key: string, label: string, table: ?string, type?: string}>, parameters: list<string>, data_items: list<array{key: string, caption: string, default_fields: list<string>, fields: list<array{key: string, caption: string, type: string, options?: list<array{value: string, label: string}>, lookup?: string}>}>}
     */
    public function definition(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): array
    {
        $penyedia = $this->penyedia($report);

        return $this->jalankan($report, $membership, fn (array $konteks): array => $penyedia->definisi(
            $this->kodeLokal($report, $penyedia),
            $konteks,
        ), $legalEntityId, $orgUnitId);
    }

    public function builtinLayout(stdClass $report, string $key, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): string
    {
        $penyedia = $this->penyedia($report);

        return $this->jalankan($report, $membership, fn (array $konteks): string => $penyedia->layoutBawaan(
            $this->kodeLokal($report, $penyedia),
            $key,
            $konteks,
        ), $legalEntityId, $orgUnitId);
    }

    /**
     * Dataset beserta format placeholder bertipenya.
     *
     * Formatnya dibaca dari definisi laporan yang sama, bukan dititipkan module di dalam
     * dataset: tipe adalah bagian dari definisi, di samping label placeholder-nya, dan satu
     * sumber berarti dataset dan definisi tidak dapat berselisih tentang kolom mana yang uang.
     * Keduanya dibaca selagi tenant masih terikat, karena presisi uang adalah setelan tenant.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function dataset(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, array $parameters): ReportData
    {
        $penyedia = $this->penyedia($report);
        $kode = $this->kodeLokal($report, $penyedia);

        [$isi, $definisi, $formats] = $this->jalankan($report, $membership, function (array $konteks) use ($penyedia, $kode, $parameters): array {
            $isi = $penyedia->dataset($kode, $konteks, $parameters);
            $definisi = $penyedia->definisi($kode, $konteks)['fields'];

            return [$isi, $definisi, $this->formats->forFields((string) $konteks['tenant_id'], $definisi, (string) $konteks['timezone'])];
        }, $legalEntityId, $orgUnitId);

        return ReportData::fromArray($isi, $formats, $definisi);
    }

    private function penyedia(stdClass $report): PenyediaLaporanModul
    {
        $penyedia = $this->daftar->untuk((string) $report->app_id);

        // Tidak ada jalur cadangan lagi. Laporan yang app-nya tidak terdaftar sebagai module
        // di runtime ini berarti katalognya menyebut app yang tidak ada di edisi terpasang;
        // dulu keadaan itu tersembunyi di balik panggilan HTTP ke alamat yang tidak menjawab.
        if ($penyedia === null) {
            throw new RenderException(
                "Laporan `{$report->code}` milik {$report->app_name}, yang tidak terpasang sebagai module ".
                'pada runtime ini.'
            );
        }

        // Module terdaftar tapi tidak mengenal kode laporannya berarti katalog dan module
        // sudah tidak sepakat — biasanya karena manifest lebih baru daripada kode yang
        // terpasang. Ia gagal dengan sebabnya, bukan dengan kode laporan yang kosong.
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
        return $this->forModule((string) $report->app_id, (string) $report->app_name, $membership, $legalEntityId, $orgUnitId, $panggilan);
    }

    /**
     * Menjalankan satu panggilan ke module `$appId` atas nama pengguna, dengan tenant aktif terikat dan
     * konteks laporan terisi. Dipakai laporan dan ekspor daftar di layar (K-27), yang menempuh aturan sama:
     * konteks dari keanggotaan, bukan dari permintaan, dan kegagalan module menjadi pesan ekspor.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $panggilan
     * @return T
     */
    public function forModule(string $appId, string $appName, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, callable $panggilan): mixed
    {
        $konteks = [
            'tenant_id' => (string) $membership->tenant_id,
            'legal_entity_id' => $legalEntityId,
            'org_unit_id' => $orgUnitId,
            'user_id' => (string) $membership->user_id,
            'permissions' => $this->apps->permissionsFor($membership, $appId),
            'data_policies' => $this->kebijakan->resolve($membership),
            // Zona waktu pengguna yang meminta, untuk "hari ini" di module (periode bawaan, nama berkas)
            // dan untuk waktu di cetakan. Dihitung di sini karena ekspor berjalan di worker tanpa sesi.
            'timezone' => $this->clock->timezoneFor($membership->user, $legalEntityId),
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
                "{$appName} tidak dapat menyiapkan data ini: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
