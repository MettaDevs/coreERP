<?php

namespace App\Console\Commands;

use App\Support\AppContentPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Membangun konfigurasi reverse proxy untuk konten UI app dari registry placement.
 *
 * Config ini artefak, bukan file yang ditulis tangan. Sumber kebenarannya adalah
 * `app_placements` + `app_releases`, sehingga path yang dilayani proxy tidak
 * mungkin menyimpang dari path yang dipancarkan shell.
 *
 * Batas yang disengaja: ini config statis. Jumlah placement tumbuh seiring tenant
 * isolated, jadi setiap provisioning menuntut render ulang dan reload proxy di
 * semua replica. Cukup untuk puluhan placement. Karena path sudah di-key
 * placement, penggantian ke resolusi dinamis nanti tidak menuntut perubahan
 * skema maupun migrasi data.
 *
 * @phpstan-type PlacementRow array{app_id:string,placement:string,release_version:string,runtime_status:string,release_status:?string,ui_service:?string}
 */
class RenderAppProxyConfigCommand extends Command
{
    protected $signature = 'app:render-proxy-config
        {--target=apache : Bentuk keluaran: apache atau nginx}
        {--output= : Tulis ke file; tanpa opsi ini keluaran dicetak ke stdout}
        {--allow-empty : Tetap keluarkan config kosong saat tidak ada placement siap}';

    protected $description = 'Render config reverse proxy /apps-content dari registry placement';

    private const SERVICE_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    public function handle(): int
    {
        $target = (string) $this->option('target');

        if (! in_array($target, ['apache', 'nginx'], true)) {
            $this->components->error("Target tidak dikenal: {$target}. Pilih apache atau nginx.");

            return self::FAILURE;
        }

        $blocks = [];
        $skipped = [];

        foreach ($this->placements() as $row) {
            $reason = $this->skipReason($row);

            if ($reason !== null) {
                $skipped[] = "{$row['app_id']} @ {$row['placement']}: {$reason}";

                continue;
            }

            $blocks[] = $target === 'apache'
                ? $this->apacheBlock($row)
                : $this->nginxBlock($row);
        }

        // Placement yang dilewati selalu dilaporkan. Config yang memotong diam-diam
        // terbaca seolah sudah lengkap, dan app yang hilang akan dikira mati.
        foreach ($skipped as $note) {
            $this->components->warn('Dilewati — '.$note);
        }

        if ($blocks === [] && ! $this->option('allow-empty')) {
            $this->components->error('Tidak ada placement siap. Pakai --allow-empty bila config kosong memang yang diinginkan.');

            return self::FAILURE;
        }

        $config = $this->header($target, count($blocks), count($skipped)).implode("\n", $blocks);
        $output = $this->stringOption('output');

        if ($output === null) {
            $this->line($config);

            return self::SUCCESS;
        }

        if (file_put_contents($output, $config) === false) {
            $this->components->error("Gagal menulis config ke {$output}.");

            return self::FAILURE;
        }

        $this->components->info(sprintf('%d placement ditulis ke %s.', count($blocks), $output));

        return self::SUCCESS;
    }

    /** @return list<array{app_id:string,placement:string,release_version:string,runtime_status:string,release_status:?string,ui_service:?string}> */
    private function placements(): array
    {
        $rows = DB::table('app_placements as placements')
            ->leftJoin('app_releases as releases', function ($join) {
                $join->on('releases.app_id', '=', 'placements.app_id')
                    ->on('releases.version', '=', 'placements.release_version');
            })
            ->orderBy('placements.placement')
            ->orderBy('placements.app_id')
            ->get([
                'placements.app_id',
                'placements.placement',
                'placements.release_version',
                'placements.runtime_status',
                'releases.status as release_status',
                'releases.ui_service',
            ]);

        $placements = [];

        foreach ($rows as $row) {
            $placements[] = [
                'app_id' => (string) $row->app_id,
                'placement' => (string) $row->placement,
                'release_version' => (string) $row->release_version,
                'runtime_status' => (string) $row->runtime_status,
                'release_status' => is_string($row->release_status) ? $row->release_status : null,
                'ui_service' => is_string($row->ui_service) ? $row->ui_service : null,
            ];
        }

        return $placements;
    }

    /** @param PlacementRow $row */
    private function skipReason(array $row): ?string
    {
        if ($row['runtime_status'] !== 'ready') {
            return "runtime belum ready (status {$row['runtime_status']})";
        }

        if ($row['release_status'] !== 'available') {
            return "release {$row['release_version']} tidak available";
        }

        if ($row['ui_service'] === null || preg_match(self::SERVICE_PATTERN, $row['ui_service']) !== 1) {
            return 'nama service UI tidak valid pada release';
        }

        return null;
    }

    /** @param PlacementRow $row */
    private function apacheBlock(array $row): string
    {
        $path = $this->path($row);
        $upstream = 'http://'.$row['ui_service'].':80/';

        // Trailing slash pada kedua sisi wajib: ia memotong prefix sehingga
        // container app menerima "/" dan asset relatifnya tetap resolve.
        return <<<CONF
        # {$row['app_id']} @ {$row['placement']} (release {$row['release_version']})
        ProxyPass {$path} {$upstream}
        ProxyPassReverse {$path} {$upstream}

        CONF;
    }

    /** @param PlacementRow $row */
    private function nginxBlock(array $row): string
    {
        $path = $this->path($row);
        $upstream = 'http://'.$row['ui_service'].':80/';

        return <<<CONF
        # {$row['app_id']} @ {$row['placement']} (release {$row['release_version']})
        location {$path} {
            proxy_pass {$upstream};
            proxy_set_header Host \$host;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
        }

        CONF;
    }

    /** @param PlacementRow $row */
    private function path(array $row): string
    {
        try {
            return AppContentPath::for($row['app_id'], $row['placement']);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                "Placement {$row['placement']} untuk {$row['app_id']} tidak dapat dipetakan ke path: ".$exception->getMessage(),
            );
        }
    }

    private function header(string $target, int $rendered, int $skipped): string
    {
        $comment = $target === 'apache' ? '#' : '#';

        return implode("\n", [
            "{$comment} Dibuat oleh `php artisan app:render-proxy-config --target={$target}`.",
            "{$comment} Jangan disunting manual — sumber kebenarannya tabel app_placements.",
            "{$comment} {$rendered} placement dilayani, {$skipped} dilewati saat render.",
            '',
            '',
        ]);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
