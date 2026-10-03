<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Console;

use App\Platform\Analytics\Datasets\DatasetRegistry;
use Illuminate\Console\Command;

/**
 * Daftar dataset analitik yang didaftarkan module, beserta hasil pemeriksaannya terhadap database
 * koneksi bawaan saat ini.
 *
 * Menjawab pertanyaan yang mahal bila dijawab dengan menebak: apakah engine melihat dataset saya, dan
 * kalau tidak, kenapa? Di runtime dataset rusak hanya dilewati dengan peringatan di log; di sini ia
 * tampil beserta sebabnya, dan perintahnya gagal supaya dapat dipakai di CI.
 */
final class AnalyticsDatasetsCommand extends Command
{
    protected $signature = 'analytics:datasets';

    protected $description = 'Tampilkan dataset analitik per module beserta hasil pemeriksaannya';

    public function handle(DatasetRegistry $registry): int
    {
        $rows = $registry->diagnose();

        if ($rows === []) {
            $this->warn('Tidak ada dataset analitik yang didaftarkan module.');

            return self::SUCCESS;
        }

        $this->table(
            ['Module', 'Kode', 'Versi', 'Field', 'Measure', 'Hasil'],
            array_map(static fn (array $row): array => [
                $row['module'],
                $row['code'],
                $row['dataset'] === null ? '-' : $row['dataset']->version,
                $row['dataset'] === null ? '-' : count($row['dataset']->fields()),
                $row['dataset'] === null ? '-' : count($row['dataset']->measures()),
                match ($row['status']) {
                    'valid' => 'Sah',
                    'unavailable' => 'Tidak tersedia: '.$row['problem'],
                    'invalid' => 'Rusak: '.$row['problem'],
                },
            ], $rows),
        );

        $invalid = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'invalid'));
        if ($invalid > 0) {
            $this->error("{$invalid} dataset rusak dan dilewati engine di runtime.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
