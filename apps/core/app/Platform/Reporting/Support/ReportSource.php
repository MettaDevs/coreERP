<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Support;

use App\Platform\Access\Support\DataPolicyAccessResolver;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\ModuleReportProvider;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Modules\Support\TenantRunnerCore;
use App\Platform\Reporting\Support\Rendering\RenderException;
use App\Platform\Tenant\Models\TenantMembership;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Dari mana definisi, layout bawaan, dan dataset sebuah laporan diambil.
 *
 * Hanya ada satu jalur: `app_id` sebuah laporan selalu module yang berjalan di dalam proses
 * ini, dan laporannya dibaca langsung lewat {@see ModuleReportProvider}. Jalur HTTP ke app di
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
final class ReportSource
{
    public function __construct(
        private readonly ModuleReportProviderRegistry $list,
        private readonly LaunchableAppCatalog $apps,
        private readonly DataPolicyAccessResolver $policy,
        private readonly TenantRunnerCore $runner,
        private readonly ValueFormats $formats,
        private readonly UserClock $clock,
    ) {}

    /**
     * @return array{fields: list<array{key: string, label: string, table: ?string, type?: string}>, parameters: list<string>, data_items: list<array{key: string, caption: string, default_fields: list<string>, fields: list<array{key: string, caption: string, type: string, options?: list<array{value: string, label: string}>, lookup?: string}>}>}
     */
    public function definition(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): array
    {
        $provider = $this->provider($report);

        return $this->run($report, $membership, fn (array $context): array => $provider->definition(
            $this->localCode($report, $provider),
            $context,
        ), $legalEntityId, $orgUnitId);
    }

    public function builtinLayout(stdClass $report, string $key, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): string
    {
        $provider = $this->provider($report);

        return $this->run($report, $membership, fn (array $context): string => $provider->defaultLayout(
            $this->localCode($report, $provider),
            $key,
            $context,
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
        $provider = $this->provider($report);
        $code = $this->localCode($report, $provider);

        [$content, $definition, $formats] = $this->run($report, $membership, function (array $context) use ($provider, $code, $parameters): array {
            $content = $provider->dataset($code, $context, $parameters);
            $definition = $provider->definition($code, $context)['fields'];

            return [$content, $definition, $this->formats->forFields((string) $context['tenant_id'], $definition, (string) $context['timezone'])];
        }, $legalEntityId, $orgUnitId);

        return ReportData::fromArray($content, $formats, $definition);
    }

    private function provider(stdClass $report): ModuleReportProvider
    {
        $provider = $this->list->providerFor((string) $report->app_id);

        // Tidak ada jalur cadangan lagi. Laporan yang app-nya tidak terdaftar sebagai module
        // di runtime ini berarti katalognya menyebut app yang tidak ada di edisi terpasang;
        // dulu keadaan itu tersembunyi di balik panggilan HTTP ke alamat yang tidak menjawab.
        if ($provider === null) {
            throw new RenderException(
                "Laporan `{$report->code}` milik {$report->app_name}, yang tidak terpasang sebagai module ".
                'pada runtime ini.'
            );
        }

        // Module terdaftar tapi tidak mengenal kode laporannya berarti katalog dan module
        // sudah tidak sepakat — biasanya karena manifest lebih baru daripada kode yang
        // terpasang. Ia gagal dengan sebabnya, bukan dengan kode laporan yang kosong.
        if (! $provider->has($this->localCode($report, $provider))) {
            throw new RenderException(
                "Laporan `{$report->code}` terdaftar di katalog tetapi tidak dikenal module {$report->app_name}. ".
                'Manifest dan kode module tidak sepadan.'
            );
        }

        return $provider;
    }

    /** Kode laporan di sisi module: kode katalog tanpa awalan id module. */
    private function localCode(stdClass $report, ModuleReportProvider $provider): string
    {
        return substr((string) $report->code, strlen($provider->moduleId()) + 1);
    }

    /**
     * Menjalankan satu panggilan module dengan tenant aktif terikat dan konteks terisi.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $call
     * @return T
     */
    private function run(stdClass $report, TenantMembership $membership, callable $call, ?string $legalEntityId, ?string $orgUnitId): mixed
    {
        return $this->forModule((string) $report->app_id, (string) $report->app_name, $membership, $legalEntityId, $orgUnitId, $call);
    }

    /**
     * Menjalankan satu panggilan ke module `$appId` atas nama pengguna, dengan tenant aktif terikat dan
     * konteks laporan terisi. Dipakai laporan dan ekspor daftar di layar (K-27), yang menempuh aturan sama:
     * konteks dari keanggotaan, bukan dari permintaan, dan kegagalan module menjadi pesan ekspor.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $call
     * @return T
     */
    public function forModule(string $appId, string $appName, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, callable $call): mixed
    {
        $context = [
            'tenant_id' => (string) $membership->tenant_id,
            'legal_entity_id' => $legalEntityId,
            'org_unit_id' => $orgUnitId,
            'user_id' => (string) $membership->user_id,
            'permissions' => $this->apps->permissionsFor($membership, $appId),
            'data_policies' => $this->policy->resolve($membership),
            // Zona waktu pengguna yang meminta, untuk "hari ini" di module (periode bawaan, nama berkas)
            // dan untuk waktu di cetakan. Dihitung di sini karena ekspor berjalan di worker tanpa sesi.
            'timezone' => $this->clock->timezoneFor($membership->user, $legalEntityId),
        ];

        try {
            return $this->runner->runFor(
                (string) $context['tenant_id'],
                static fn (): mixed => $call($context),
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
