<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Console\Commands;

use Illuminate\Console\Command;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;

/**
 * Membangun ulang layout bawaan di `resources/laporan/` dari pembangunnya di
 * `src/Reporting/Layouts/Builtin/`, satu berkas per laporan.
 *
 * Layout bawaan adalah berkas Office yang ikut release. Ia dibangkitkan dari kode,
 * bukan dirawat tangan di Word, supaya perubahannya terbaca di review dan hasilnya sama
 * di mesin siapa pun. Berkas hasilnya tetap di-commit: runtime membaca berkas, bukan
 * menjalankan command ini.
 *
 * Pembangun ditemukan dari foldernya, bukan dari daftar di sini — sama seperti rute di
 * `routes/api/` — supaya menambah laporan cukup menambah satu berkas. Sebagai gantinya,
 * pembangun dicocokkan dengan definisi laporan sebelum apa pun ditulis: layout yang
 * dinyatakan definisi tetapi tidak punya pembangun tidak pernah dibangun, dan pembangun
 * tanpa definisi menulis berkas yang tidak pernah dibaca. Keduanya diam kalau tidak
 * ditolak di sini.
 *
 *     php artisan management-aset:build-builtin-layouts
 */
final class BuildBuiltinLayouts extends Command
{
    protected $signature = 'management-aset:build-builtin-layouts';

    protected $description = 'Bangun ulang berkas layout bawaan Word/Excel di resources/laporan.';

    public function handle(ReportRegistry $registry): int
    {
        $builders = [];
        foreach ($this->builders() as $builder) {
            $code = $builder->reportCode();
            if (isset($builders[$code])) {
                $this->error(sprintf('Laporan `%s` punya dua pembangun layout: %s dan %s.', $code, $builders[$code]::class, $builder::class));

                return self::FAILURE;
            }
            $builders[$code] = $builder;
        }

        $problems = [];
        foreach ($registry->all() as $definition) {
            if ($definition->builtinLayouts() !== [] && ! isset($builders[$definition->code()])) {
                $problems[] = "Laporan `{$definition->code()}` menyatakan layout bawaan, tetapi tidak ada pembangunnya di src/Reporting/Layouts/Builtin.";
            }
        }
        foreach (array_keys($builders) as $code) {
            if (! $registry->has($code)) {
                $problems[] = "Pembangun layout untuk `{$code}` tidak punya definisi laporan yang terdaftar.";
            }
        }
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        foreach ($registry->all() as $definition) {
            foreach ($definition->builtinLayouts() as $layout) {
                // Jalurnya dari `BuiltinLayout::path()`, jalur yang sama yang dibaca saat
                // mencetak — bukan dari `resource_path()`, yang di dalam runtime Core menunjuk
                // folder resources milik Core.
                $path = $layout->path($definition->code());
                $builders[$definition->code()]->build($layout, $path);
                $this->line("  ditulis: {$path}");
            }
        }
        $this->info('Layout bawaan dibangun ulang.');

        return self::SUCCESS;
    }

    /** @return list<BuiltinLayoutBuilder> */
    private function builders(): array
    {
        $builders = [];
        foreach (glob(dirname(__DIR__, 2).'/Reporting/Layouts/Builtin/*.php') ?: [] as $file) {
            $builder = app('Modules\\Apperp\\ManagementAset\\Reporting\\Layouts\\Builtin\\'.basename($file, '.php'));
            if ($builder instanceof BuiltinLayoutBuilder) {
                $builders[] = $builder;
            }
        }

        return $builders;
    }
}
