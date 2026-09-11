<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Menampilkan module yang ditemukan runtime.
 *
 * Perintah ini menjawab pertanyaan yang akan sering muncul dan mahal bila dijawab dengan
 * menebak: apakah runtime melihat module saya? Selama jawabannya hanya bisa didapat dengan
 * membuka halaman dan menunggu 404, setiap kesalahan kecil pada manifest berubah menjadi
 * pencarian panjang.
 */
final class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'Tampilkan module yang ditemukan runtime beserta versinya';

    public function handle(ModuleRegistry $registry): int
    {
        $module = $registry->semua();

        if ($module === []) {
            $this->warn('Tidak ada module yang ditemukan di folder modules/.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Nama', 'Versi', 'Penerbit', 'Jenis', 'Awalan tabel'],
            array_map(static fn ($m): array => [
                $m->id,
                $m->nama,
                $m->versi,
                $m->penerbit,
                $m->bahanUjiInternal() ? $m->jenis.' (tidak ikut ke pelanggan)' : $m->jenis,
                $m->awalanTabel,
            ], $module),
        );

        return self::SUCCESS;
    }
}
