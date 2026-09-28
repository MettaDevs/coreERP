<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\EditionModules;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Mencetak daftar modul yang ikut ke dalam image yang dibagikan ke klien.
 *
 * Ini perintah yang dipanggil skrip pembangun image: keluarannya dipakai untuk memutuskan folder
 * modul mana yang disalin. Karena itu ia punya dua bentuk keluaran — daftar per baris untuk dibaca
 * skrip, dan daftar berhias untuk dibaca orang.
 *
 * Ia menggantikan `edition:resolve`, yang menuntut nama berkas edisi sebagai argumen. Argumen itu
 * hilang bersama folder `editions/`: tidak ada lagi pilihan untuk dibuat, karena image yang
 * dibagikan ke klien hanya satu dan isinya selalu seluruh modul. Alasannya di
 * `App\Support\Modules\EditionModules`.
 */
final class EditionModulesCommand extends Command
{
    protected $signature = 'edition:modules
        {--daftar : Cetak satu id per baris, tanpa hiasan, untuk dibaca skrip}';

    protected $description = 'Cetak daftar modul yang ikut ke dalam image yang dibagikan ke klien';

    public function handle(EditionModules $modul): int
    {
        try {
            $daftar = $modul->daftar();
        } catch (RuntimeException $kesalahan) {
            $this->components->error($kesalahan->getMessage());

            return self::FAILURE;
        }

        if ($this->option('daftar')) {
            foreach ($daftar as $id) {
                $this->line($id);
            }

            return self::SUCCESS;
        }

        if ($daftar === []) {
            // Bukan kesalahan, tetapi juga bukan keadaan yang normal hari ini: seluruh modul di
            // repo ternyata bahan uji. Dikatakan, bukan dibiarkan terbaca sebagai daftar kosong.
            $this->components->warn('Tidak satu pun modul ikut ke dalam image; seluruh modul di repo adalah bahan uji internal.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Image memuat %d modul:', count($daftar)));
        $this->components->bulletList($daftar);

        return self::SUCCESS;
    }
}
