<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\EditionResolver;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Mencetak daftar modul yang benar-benar masuk ke sebuah image edisi.
 *
 * Ini perintah yang dipanggil skrip pembangun image: keluarannya dipakai untuk memutuskan
 * folder modul mana yang disalin. Karena itu ia punya dua bentuk keluaran — daftar per baris
 * untuk dibaca skrip, dan tabel untuk dibaca orang.
 *
 * Ia sengaja tidak menyentuh database. Image edisi dibangun di CI tanpa database sama sekali,
 * dan sebuah perintah yang diam-diam membutuhkan koneksi akan gagal di sana dengan pesan yang
 * tidak menyebut edisi sedikit pun.
 */
final class EditionResolveCommand extends Command
{
    protected $signature = 'edition:resolve
        {edisi : Nama berkas edisi tanpa akhiran, misalnya `apotek-sejahtera`}
        {--daftar : Cetak satu id per baris, tanpa hiasan, untuk dibaca skrip}';

    protected $description = 'Hitung daftar modul sebuah edisi dari manifest-nya, beserta dependency dan modul penghubungnya';

    public function handle(EditionResolver $resolver): int
    {
        $edisi = (string) $this->argument('edisi');
        $berkas = dirname(base_path(), 2).'/editions/'.$edisi.'.yaml';

        try {
            $modul = $resolver->dariBerkas($berkas);
        } catch (RuntimeException $kesalahan) {
            $this->components->error($kesalahan->getMessage());

            return self::FAILURE;
        }

        if ($this->option('daftar')) {
            foreach ($modul as $id) {
                $this->line($id);
            }

            return self::SUCCESS;
        }

        if ($modul === []) {
            // Bukan kesalahan. Edisi Core saja adalah bentuk yang sah, dan ia justru pemeriksaan
            // kebocoran yang paling tajam: apa pun jejak modul di dalam image-nya adalah cacat.
            $this->components->info(sprintf('Edisi "%s" tidak memuat satu pun modul; hanya Core.', $edisi));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Edisi "%s" memuat %d modul:', $edisi, count($modul)));
        $this->components->bulletList($modul);

        return self::SUCCESS;
    }
}
