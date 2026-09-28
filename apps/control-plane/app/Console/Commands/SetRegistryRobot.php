<?php

declare(strict_types=1);

namespace ControlPlane\Console\Commands;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Registry\HarborClient;
use ControlPlane\Registry\RegistrySettings;
use ControlPlane\Registry\RegistryUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan robot sistem Harbor yang dipakai konsol ini, dibaca dari stdin.
 *
 *   sudo cat /etc/coreerp/registry/robot-konsol.env | docker exec -i <container konsol> php artisan registry:robot-sistem
 *
 * Masukannya berkas yang ditulis `deploy/registry/atur-harbor.sh` — `REGISTRY_USERNAME='…'` dan
 * `REGISTRY_PASSWORD='…'` — dan dibaca sebagai data, tidak pernah sebagai argumen: argumen terlihat di
 * daftar proses dan riwayat shell.
 *
 * Kredensial diperiksa ke Harbor **sebelum** tersimpan. Robot yang salah ketik tidak menggantikan robot
 * yang sedang bekerja; kegagalannya baru akan terlihat pada pemasangan klien berikutnya, di lokasi klien.
 */
final class SetRegistryRobot extends Command
{
    protected $signature = 'registry:robot-sistem {--berkas= : Baca dari berkas ini, bukan dari stdin}';

    protected $description = 'Menyimpan robot sistem Harbor dari stdin, sesudah diperiksa ke Harbor';

    public function handle(RegistrySettings $settings, HarborClient $harbor): int
    {
        $path = $this->option('berkas');
        $input = is_string($path) && $path !== ''
            ? (is_readable($path) ? (string) file_get_contents($path) : '')
            : (string) stream_get_contents(STDIN);
        $name = self::value($input, 'REGISTRY_USERNAME');
        $secret = self::value($input, 'REGISTRY_PASSWORD');

        if ($name === null || $secret === null) {
            $this->error('Masukan harus memuat REGISTRY_USERNAME dan REGISTRY_PASSWORD.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($settings, $harbor, $name, $secret): void {
                $settings->storeRobot($name, $secret, null);
                // Di dalam transaksi yang sama: pemeriksaan membaca robot yang baru disimpan, dan kegagalannya
                // mengembalikan robot sebelumnya.
                $harbor->verifyRobot();

                OperatorAudit::recordBySystem(null, 'console.registry_robot.stored', 'console_setting', RegistrySettings::ROBOT_NAME, [
                    'robot' => $name,
                ]);
            });
        } catch (RegistryUnavailable $e) {
            $this->error('Tidak disimpan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Robot sistem %s tersimpan dan diterima Harbor.', $name));

        return self::SUCCESS;
    }

    private static function value(string $input, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/')."='([^'\\r\\n]+)'\\s*$/m", $input, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
