<?php

namespace App\Platform\Geography\Console;

use Illuminate\Console\Command;

final class ImportIndonesianAddressHierarchyCommand extends Command
{
    protected $signature = 'address:import-indonesia
                            {--dry-run : Validate dataset and report changes without writing}
                            {--force : Update existing master names from the dataset}
                            {--source= : Folder containing the Indonesian hierarchy JSON files}
                            {--level= : Import only province, regency, district, or village}';

    protected $description = 'Import Indonesian reference regions while preserving existing record IDs';

    public function handle(): int
    {
        $source = (string) ($this->option('source') ?: base_path('database/data'));
        $level = $this->option('level');
        if ($level !== null && ! in_array($level, ['province', 'regency', 'district', 'village'], true)) {
            $this->error('Level wilayah tidak dikenal.');

            return self::FAILURE;
        }
        try {
            $import = require database_path('data/import_indonesia_hierarchy.php');
            $stats = $import($source, (bool) $this->option('dry-run'), (bool) $this->option('force'), $level);
        } catch (\Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
        $rows = [];
        foreach ($stats as $key => $count) {
            $rows[] = [$key, $count];
        }
        $this->table(['Hasil', 'Jumlah'], $rows);
        $this->info($this->option('dry-run') ? 'Validasi selesai. Database belum diubah.' : 'Impor selesai. ID dan alamat lama tetap dipertahankan.');

        return self::SUCCESS;
    }
}
