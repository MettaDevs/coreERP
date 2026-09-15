<?php

declare(strict_types=1);

namespace ControlPlane\Registry;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Sites\SiteRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Kredensial registry untuk agen: satu robot pull-only per operasi `install` atau `upgrade` (CP-02).
 *
 * ## Lahir saat diminta, mati bersama operasinya
 *
 * Robot dibuat ketika agen yang memegang operasi memintanya, bukan saat operasi dibuat: operasi yang
 * tidak pernah diambil tidak meninggalkan robot. Ia dihapus begitu operasinya tidak lagi dipegang —
 * selesai, gagal, tenggatnya habis, dibatalkan, atau situsnya dicabut.
 *
 * Konsol ini tidak punya penjadwal, jadi penghapusan tidak dijadwalkan melainkan disapu:
 * {@see releaseClosed()} dipanggil setiap kali agen menyambung dan setiap kali operator mencabut situs.
 * Robot yang penghapusannya gagal tetap tercatat di barisnya dan dicoba lagi pada sapuan berikutnya.
 * Umur robot di Harbor (`sites.registry_robot_days`) adalah penjaga terakhir bila tidak ada sapuan
 * yang pernah berhasil.
 *
 * ## Pemanggilan ulang
 *
 * Agen meminta kredensial lagi ketika pull dijawab 401 di tengah jalan. Robot lama dihapus lalu yang baru
 * dibuat: Harbor tidak memberi robot sistem izin memutar rahasia robot lain, jadi "robot yang sama dengan
 * rahasia baru" tidak tersedia. Satu operasi tetap memegang paling banyak satu robot.
 *
 * ## Yang dijaga
 *
 * Token yang sudah diterbitkan Harbor untuk robot yang kemudian dihapus tetap berlaku sampai umur tokennya
 * habis — terukur ±umur token + 60 detik (`deploy/registry/SPIKE.md`). Penghapusan menutup login baru,
 * bukan token yang sedang dipakai.
 */
final class RegistryCredentials
{
    public const PULLING_OPERATIONS = ['install', 'upgrade'];

    public function __construct(
        private readonly HarborClient $harbor,
        private readonly RegistrySettings $settings,
    ) {}

    /**
     * @return array{registry: string, username: string, password: string, expires_at: string}
     *
     * @throws SiteRejected bila situs ini tidak memegang operasi penarik dengan id itu
     * @throws RegistryUnavailable bila registry belum disetel atau tidak menjawab
     */
    public function issue(Site $site, string $operationId, ?string $ipAddress): array
    {
        $operation = $this->heldOperation($site, $operationId);

        if ($operation->registry_robot_id !== null) {
            $this->deleteRobot($site, $operation, 'diganti', $ipAddress);
        }

        $robot = $this->harbor->createPullRobot(
            'situs-'.Str::lower($operation->id).'-'.Str::lower(Str::random(6)),
            (int) config('sites.registry_robot_days'),
            sprintf('Situs %s, operasi %s %s. Dihapus admin.erp saat operasinya ditutup.', $site->id, $operation->operation, $operation->id),
        );

        $recorded = DB::transaction(function () use ($site, $operationId, $robot, $ipAddress): bool {
            $locked = SiteOperation::query()->whereKey($operationId)->where('site_id', $site->id)->lockForUpdate()->first();

            // Operasi dapat ditutup selama robot dibuat. Robot yang lahir untuk operasi yang sudah tidak
            // dipegang tidak dicatat, dan dihapus di bawah sebelum rahasianya diberikan kepada siapa pun.
            if (! $locked instanceof SiteOperation || ! $this->held($locked) || $locked->registry_robot_id !== null) {
                return false;
            }

            $locked->forceFill([
                'registry_robot_id' => $robot['id'],
                'registry_robot_name' => $robot['name'],
            ])->save();

            OperatorAudit::recordBySystem($ipAddress, 'site.registry_robot.issued', 'site', $site->id, [
                'operation_id' => $locked->id,
                'operation' => $locked->operation,
                'robot' => $robot['name'],
                'expires_at' => $robot['expires_at']->toIso8601String(),
            ]);

            return true;
        });

        if (! $recorded) {
            $this->harbor->deleteRobot($robot['id']);

            throw new SiteRejected('operation_not_held', 'Operasi ini tidak lagi dipegang agen ini.');
        }

        return [
            'registry' => $this->settings->host(),
            'username' => $robot['name'],
            'password' => $robot['secret'],
            'expires_at' => $robot['expires_at']->toIso8601String(),
        ];
    }

    /**
     * Menghapus robot milik operasi situs ini yang tidak lagi dipegang agen. Tidak pernah melempar: sapuan
     * dipanggil dari jalur agen dan operator yang tidak boleh gagal karena Harbor sedang tidak menjawab.
     *
     * `includeRunning` untuk situs yang dicabut: agennya tidak akan pernah melapor lagi, jadi operasi yang
     * masih `running` pun sudah tidak dipegang siapa pun.
     */
    public function releaseClosed(Site $site, ?string $ipAddress, bool $includeRunning = false): void
    {
        $operations = SiteOperation::query()
            ->where('site_id', $site->id)
            ->whereNotNull('registry_robot_id')
            ->get();

        foreach ($operations as $operation) {
            if (! $includeRunning && $this->held($operation)) {
                continue;
            }

            try {
                $this->deleteRobot($site, $operation, $includeRunning ? 'situs_dicabut' : 'operasi_ditutup', $ipAddress);
            } catch (RegistryUnavailable $e) {
                Log::warning('Robot registry situs belum terhapus; dicoba lagi pada sapuan berikutnya.', [
                    'situs' => $site->id,
                    'operasi' => $operation->id,
                    'robot' => $operation->registry_robot_name,
                    'sebab' => $e->getMessage(),
                ]);
            }
        }
    }

    private function heldOperation(Site $site, string $operationId): SiteOperation
    {
        $operation = SiteOperation::query()->whereKey($operationId)->where('site_id', $site->id)->first();

        if (! $operation instanceof SiteOperation || ! $this->held($operation)) {
            throw new SiteRejected('operation_not_held', 'Situs ini tidak memegang operasi install atau upgrade dengan id itu.');
        }

        return $operation;
    }

    private function held(SiteOperation $operation): bool
    {
        return in_array($operation->operation, self::PULLING_OPERATIONS, true)
            && $operation->status === 'running'
            && $operation->lease_until !== null
            && $operation->lease_until->isFuture();
    }

    private function deleteRobot(Site $site, SiteOperation $operation, string $reason, ?string $ipAddress): void
    {
        $robotId = (int) $operation->registry_robot_id;
        $robotName = (string) $operation->registry_robot_name;

        $this->harbor->deleteRobot($robotId);

        DB::transaction(function () use ($site, $operation, $robotId, $robotName, $reason, $ipAddress): void {
            // Hanya bila baris masih menunjuk robot yang baru dihapus: sapuan lain yang berjalan bersamaan
            // mungkin sudah mengosongkannya, atau pemanggilan ulang sudah mencatat robot pengganti.
            $cleared = SiteOperation::query()
                ->whereKey($operation->id)
                ->where('registry_robot_id', $robotId)
                ->update(['registry_robot_id' => null, 'registry_robot_name' => null, 'updated_at' => now()]);

            if ($cleared === 1) {
                OperatorAudit::recordBySystem($ipAddress, 'site.registry_robot.deleted', 'site', $site->id, [
                    'operation_id' => $operation->id,
                    'robot' => $robotName,
                    'reason' => $reason,
                ]);
            }
        });

        $operation->forceFill(['registry_robot_id' => null, 'registry_robot_name' => null]);
    }
}
